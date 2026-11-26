<?php

declare(strict_types=1);

use App\Domain\GenerationRequest;
use App\Domain\Money;
use App\Domain\Pricing;
use App\Domain\RateCard;
use App\Domain\TokenUsage;

/**
 * The provider's published pricing, written out rather than read from
 * configuration.
 *
 * A unit test asserting exact money has to state the rates it is asserting
 * against. Reading them from config would make the expected figures move
 * whenever the configuration did, which is the opposite of what these tests
 * are for — they exist to catch a change in the arithmetic, not to agree with
 * whatever the arithmetic currently produces.
 *
 * @param  array<string, mixed>  $overrides
 */
function publishedPricing(array $overrides = []): array
{
    return array_replace_recursive([
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1_100, '1536x1024' => 1_650, '1024x1536' => 1_650],
                'high' => ['1024x1024' => 4_300, '1536x1024' => 6_450, '1024x1536' => 6_450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2_000],
                'high' => ['min' => 1_000, 'max' => 8_000],
            ],
        ],
    ], $overrides);
}

function pricing(int $markupBasisPoints = 2_500): Pricing
{
    return new Pricing(RateCard::fromPricingPayload(publishedPricing(), $markupBasisPoints));
}

describe('the provider cost', function () {
    it('reproduces the worked example from the provider documentation', function () {
        // The documentation works through the most a generation can cost: a
        // high quality 1536x1024 image with a 400-character prompt and two
        // reference images, assuming the most text and reasoning tokens
        // possible. It arrives at $0.373875.
        //
        // Matching it to the last digit is the strongest available evidence
        // that this implementation has understood the pricing model, and not
        // merely produced a number of roughly the right size.
        $request = new GenerationRequest(
            prompt: str_repeat('a', 400),
            size: '1536x1024',
            quality: 'high',
            referenceImageUrls: ['https://example.test/1.png', 'https://example.test/2.png'],
        );

        $usage = pricing()->maximumUsage($request);

        expect($usage->inputTextTokens)->toBe(115)
            ->and($usage->inputImageTokens)->toBe(1_530)
            ->and($usage->outputTokens)->toBe(6_450)
            ->and($usage->reasoningTokens)->toBe(8_000);

        expect(pricing()->maximumProviderCost($request)->toUsdString())->toBe('0.373875000');
    });

    it('prices each token type at its own published rate', function (TokenUsage $usage, string $expected) {
        expect(pricing()->providerCost($usage)->toUsdString())->toBe($expected);
    })->with([
        'input text alone, at $5.00 per million' => [
            new TokenUsage(inputTextTokens: 1_000, inputImageTokens: 0, outputTokens: 0, reasoningTokens: 0),
            '0.005000000',
        ],
        'input image alone, at $10.00 per million' => [
            new TokenUsage(inputTextTokens: 0, inputImageTokens: 765, outputTokens: 0, reasoningTokens: 0),
            '0.007650000',
        ],
        'output alone, at $40.00 per million' => [
            new TokenUsage(inputTextTokens: 0, inputImageTokens: 0, outputTokens: 1_100, reasoningTokens: 0),
            '0.044000000',
        ],
        'reasoning alone, at $12.50 per million' => [
            new TokenUsage(inputTextTokens: 0, inputImageTokens: 0, outputTokens: 0, reasoningTokens: 840),
            '0.010500000',
        ],
        'nothing costs nothing' => [TokenUsage::none(), '0.000000000'],
    ]);

    it('is exact, with nothing left to round', function () {
        // A single reasoning token costs 12.5 micro-dollars. Any unit coarser
        // than a nanodollar would round it, and the error would then be
        // multiplied by the token count and again by the markup.
        $oneToken = pricing()->providerCost(
            new TokenUsage(inputTextTokens: 0, inputImageTokens: 0, outputTokens: 0, reasoningTokens: 1),
        );

        expect($oneToken->nanos)->toBe(12_500);

        // Which means the cost of a thousand of them is exactly a thousand
        // times the cost of one — true only because nothing was rounded.
        $thousandTokens = pricing()->providerCost(
            new TokenUsage(inputTextTokens: 0, inputImageTokens: 0, outputTokens: 0, reasoningTokens: 1_000),
        );

        expect($thousandTokens->nanos)->toBe($oneToken->nanos * 1_000);
    });
});

describe('the charge', function () {
    it('applies the markup to the exact provider cost', function () {
        $usage = new TokenUsage(
            inputTextTokens: 12,
            inputImageTokens: 0,
            outputTokens: 1_100,
            reasoningTokens: 840,
        );

        $providerCost = pricing()->providerCost($usage);

        expect($providerCost->toUsdString())->toBe('0.054560000')
            ->and(pricing()->charge($providerCost)->toUsdString())->toBe('0.068200000');
    });

    it('would be wrong if the provider cost were rounded to cents first', function () {
        $usage = new TokenUsage(
            inputTextTokens: 12,
            inputImageTokens: 0,
            outputTokens: 1_100,
            reasoningTokens: 840,
        );

        $correct = pricing()->charge(pricing()->providerCost($usage));

        // $0.054560000 rounded to the nearest cent is $0.05, and marking that
        // up gives $0.0625 instead of $0.0682 — out by more than 8%, on every
        // generation, and in the provider's favour rather than ours.
        $ifRoundedToCentsFirst = Money::fromUsd('0.05')->withMarkup(2_500);

        expect($correct->toUsdString())->toBe('0.068200000')
            ->and($ifRoundedToCentsFirst->toUsdString())->toBe('0.062500000');
    });

    it('charges nothing when no markup is configured', function () {
        $usage = new TokenUsage(inputTextTokens: 0, inputImageTokens: 0, outputTokens: 1_100, reasoningTokens: 0);

        $pricing = pricing(markupBasisPoints: 0);

        expect($pricing->charge($pricing->providerCost($usage))->toUsdString())->toBe('0.044000000');
    });
});

describe('the quote', function () {
    it('is a ceiling the final charge does not exceed', function () {
        $request = new GenerationRequest('a lighthouse at dusk', '1024x1024', 'medium');

        $quote = pricing()->quote($request);

        // The actual generation uses fewer text tokens than the top of the
        // variance band and fewer reasoning tokens than the top of the range,
        // which is the normal case.
        $actual = pricing()->charge(pricing()->providerCost(new TokenUsage(
            inputTextTokens: 5,
            inputImageTokens: 0,
            outputTokens: 1_100,
            reasoningTokens: 840,
        )));

        expect($quote->isGreaterThan($actual))->toBeTrue();
    });

    it('cannot be exceeded by the worst case it was built from', function () {
        $request = new GenerationRequest(str_repeat('a', 400), '1536x1024', 'high');

        $quote = pricing()->quote($request);
        $worstCase = pricing()->charge(pricing()->providerCost(pricing()->maximumUsage($request)));

        expect($quote->equals($worstCase))->toBeTrue();
    });

    it('rounds the text token allowance up, not down', function () {
        // A 7-character prompt estimates at ceil(7 / 4) = 2 tokens, and 15%
        // more than 2 is 2.3. Rounding that down would produce a ceiling the
        // real count could exceed, which is not a ceiling.
        $usage = pricing()->maximumUsage(new GenerationRequest('abcdefg', '1024x1024', 'low'));

        expect($usage->inputTextTokens)->toBe(3);
    });

    it('takes output tokens from the published table rather than estimating them', function (string $size, string $quality, int $expected) {
        $usage = pricing()->maximumUsage(new GenerationRequest('a prompt', $size, $quality));

        expect($usage->outputTokens)->toBe($expected);
    })->with([
        ['1024x1024', 'low', 280],
        ['1536x1024', 'low', 420],
        ['1024x1024', 'medium', 1_100],
        ['1024x1536', 'medium', 1_650],
        ['1024x1024', 'high', 4_300],
        ['1536x1024', 'high', 6_450],
    ]);

    it('takes the top of the reasoning range for the chosen quality', function (string $quality, int $expected) {
        $usage = pricing()->maximumUsage(new GenerationRequest('a prompt', '1024x1024', $quality));

        expect($usage->reasoningTokens)->toBe($expected);
    })->with([
        ['low', 500],
        ['medium', 2_000],
        ['high', 8_000],
    ]);

    it('refuses to quote a combination the provider does not publish', function () {
        pricing()->quote(new GenerationRequest('a prompt', '4096x4096', 'high'));
    })->throws(InvalidArgumentException::class);
});

describe('the rates a generation was created at', function () {
    it('prices a completed generation on those rates, not on the current ones', function () {
        $usage = new TokenUsage(inputTextTokens: 0, inputImageTokens: 0, outputTokens: 1_000, reasoningTokens: 0);

        // At the published output rate of $40.00 per million.
        expect(pricing()->providerCost($usage)->toUsdString())->toBe('0.040000000');

        // The same generation, created when output tokens were half that.
        $atOlderRates = pricing()->withRates([
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '20.00',
            'reasoning_tokens' => '12.50',
        ]);

        expect($atOlderRates->providerCost($usage)->toUsdString())->toBe('0.020000000');
    });

    it('round-trips the rates it was given', function () {
        $rates = [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ];

        expect(pricing()->withRates($rates)->rateCard()->toArray())->toBe($rates);
    });
});
