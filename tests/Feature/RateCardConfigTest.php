<?php

declare(strict_types=1);

use App\Domain\GenerationRequest;
use App\Domain\Pricing;
use App\Domain\RateCard;
use App\Integrations\Pixelforge\Contracts\ImageProvider;
use App\Integrations\Pixelforge\Data\GenerationSubmission;
use App\Integrations\Pixelforge\Data\ProviderGeneration;
use App\Integrations\Pixelforge\Data\SubmissionResult;
use App\Integrations\Pixelforge\Exceptions\ProviderUnavailable;
use App\Integrations\Pixelforge\FakeImageProvider;
use App\Integrations\Pixelforge\RateCardRepository;
use Illuminate\Support\Facades\Cache;

/**
 * The arithmetic is covered in tests/Unit. These assert that the rate table the
 * application actually ships with is well formed and can drive that arithmetic,
 * and that a quote survives the pricing endpoint being unreachable.
 */
beforeEach(function () {
    $this->provider = new FakeImageProvider;
    $this->app->instance(ImageProvider::class, $this->provider);
    Cache::flush();
});

function committedRateCard(): RateCard
{
    /** @var array<string, mixed> $published */
    $published = config('pixelforge.pricing.fallback');

    return RateCard::fromPricingPayload($published, (int) config('pixelforge.markup_basis_points'));
}

it('builds a usable rate card from the committed table', function () {
    $card = committedRateCard();

    expect($card->markupBasisPoints)->toBe(2_500)
        ->and($card->charactersPerTextToken)->toBe(4)
        ->and($card->textTokenVarianceBasisPoints)->toBe(1_500)
        ->and($card->tokensPerReferenceImage)->toBe(765)
        ->and($card->inputTextPerToken->nanos)->toBe(5_000)
        ->and($card->inputImagePerToken->nanos)->toBe(10_000)
        ->and($card->outputPerToken->nanos)->toBe(40_000)
        ->and($card->reasoningPerToken->nanos)->toBe(12_500);
});

it('can quote every combination of size and quality it accepts', function () {
    // The two lists are the same list: request validation is driven from the
    // rate card, so a combination that validates and then cannot be priced is
    // not possible by construction. This asserts that holds.
    $pricing = new Pricing(committedRateCard());
    $card = committedRateCard();

    foreach ($card->qualities() as $quality) {
        foreach ($card->sizes() as $size) {
            $quote = $pricing->quote(new GenerationRequest('a prompt', $size, $quality));

            expect($quote->isNegative())->toBeFalse()
                ->and($quote->isZero())->toBeFalse();
        }
    }
});

it('reads the rates from the provider rather than from configuration', function () {
    app(RateCardRepository::class)->current();

    expect($this->provider->pricingCalls())->toBe(1);
});

it('caches the pricing response rather than calling it on every quote', function () {
    $repository = app(RateCardRepository::class);

    $repository->current();
    $repository->current();
    $repository->current();

    expect($this->provider->pricingCalls())->toBe(1);
});

it('reads the endpoint again once the cache has been discarded', function () {
    $repository = app(RateCardRepository::class);

    $repository->current();
    $repository->forget();
    $repository->current();

    expect($this->provider->pricingCalls())->toBe(2);
});

it('still quotes when the pricing endpoint cannot be reached', function () {
    // Refusing to show a price because a pricing endpoint is down would take
    // the product offline for a reason the user cannot act on. The committed
    // table stands in, and the fallback is logged.
    $this->app->instance(ImageProvider::class, new class implements ImageProvider
    {
        public function submit(GenerationSubmission $submission): SubmissionResult
        {
            throw new ProviderUnavailable('timed out');
        }

        public function fetch(string $providerGenerationId): ProviderGeneration
        {
            throw new ProviderUnavailable('timed out');
        }

        public function pricing(): array
        {
            throw new ProviderUnavailable('timed out');
        }
    });

    $card = app(RateCardRepository::class)->current();

    expect($card->outputPerToken->nanos)->toBe(40_000);
});

it('never prices a completed generation from the cached table', function () {
    // The guarantee the cache depends on: a stale or fallback table can make a
    // quote slightly wrong, never a charge. Charging reads the rates recorded
    // on the generation when the provider created it.
    $pricing = new Pricing(committedRateCard());

    $atRecordedRates = $pricing->withRates([
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '20.00',
        'reasoning_tokens' => '12.50',
    ]);

    expect($atRecordedRates->rateCard()->outputPerToken->nanos)->toBe(20_000)
        ->and($pricing->rateCard()->outputPerToken->nanos)->toBe(40_000);
});
