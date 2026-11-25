<?php

declare(strict_types=1);

use App\Domain\GenerationRequest;
use App\Domain\Money;
use App\Domain\Pricing;
use App\Domain\RateCard;
use App\Domain\TokenUsage;

/**
 * Rates are fixed here rather than read from configuration, so these tests
 * assert the arithmetic rather than the current rate table. Correcting a
 * published rate should not require touching them.
 */
function rateCard(array $overrides = []): RateCard
{
    return new RateCard(
        inputPerToken: $overrides['input'] ?? '0.000005',
        outputPerToken: $overrides['output'] ?? '0.000040',
        perReferenceImage: $overrides['image'] ?? '0.002000',
        markupBasisPoints: $overrides['markup'] ?? 2_500,
        charactersPerToken: $overrides['chars'] ?? 4,
        estimatedOutputTokens: $overrides['tokens'] ?? [
            '1024x1024' => ['standard' => 1_000, 'hd' => 4_000],
        ],
    );
}

it('prices a completed generation from its token usage', function (int $input, int $output, int $images, string $expectedUsd) {
    $cost = (new Pricing(rateCard()))->providerCost(new TokenUsage($input, $output, $images));

    expect($cost->toUsdString())->toBe($expectedUsd);
})->with([
    'input only' => [1_000, 0, 0, '0.005000'],
    'output only' => [0, 1_000, 0, '0.040000'],
    'input and output' => [200, 1_056, 0, '0.043240'],
    'with reference images' => [200, 1_056, 2, '0.047240'],
    'a single token' => [1, 0, 0, '0.000005'],
    'nothing used' => [0, 0, 0, '0.000000'],
]);

it('charges the provider cost plus the markup', function () {
    $pricing = new Pricing(rateCard());
    $cost = $pricing->providerCost(new TokenUsage(200, 1_056));

    expect($cost->toUsdString())->toBe('0.043240')
        ->and($pricing->charge($cost)->toUsdString())->toBe('0.054050');
});

it('accumulates at full precision and rounds once', function () {
    $pricing = new Pricing(rateCard(['input' => '0.0000033', 'output' => '0.0000077']));

    // 3 x 0.0000033 = 0.0000099 and 7 x 0.0000077 = 0.0000539.
    // Rounding each component to the nearest micro-dollar first would give
    // 10 + 54 = 64 micros. Summing first and rounding once gives 0.0000638,
    // which is 64 micros as well here — but the components are carried at
    // twelve decimal places, so the error cannot accumulate across a longer
    // sum. The assertion below pins the exact figure.
    $cost = $pricing->providerCost(new TokenUsage(3, 7));

    expect($cost->micros)->toBe(64)
        ->and($cost->toUsdString())->toBe('0.000064');
});

it('does not round a cost to the nearest cent before applying the markup', function () {
    $pricing = new Pricing(rateCard());
    $cost = $pricing->providerCost(new TokenUsage(200, 1_056));

    $correct = $pricing->charge($cost);
    $ifRoundedToCentsFirst = $pricing->charge(Money::fromUsd('0.04'));

    // A difference of $0.004050 on one generation, in the provider's favour on
    // some inputs and the user's on others, and compounding across every
    // generation the platform ever runs.
    expect($correct->micros)->toBe(54_050)
        ->and($ifRoundedToCentsFirst->micros)->toBe(50_000)
        ->and($correct->minus($ifRoundedToCentsFirst)->toUsdString())->toBe('0.004050');
});

it('estimates a cost before the generation has run', function () {
    $pricing = new Pricing(rateCard());

    // A 200-character prompt is 50 input tokens at four characters each, and a
    // standard 1024x1024 image is expected to produce 1,000 output tokens.
    $request = new GenerationRequest(str_repeat('a', 200), '1024x1024', 'standard');

    expect($pricing->estimatedUsage($request)->inputTokens)->toBe(50)
        ->and($pricing->estimatedUsage($request)->outputTokens)->toBe(1_000)
        ->and($pricing->estimatedProviderCost($request)->toUsdString())->toBe('0.040250')
        ->and($pricing->estimate($request)->toUsdString())->toBe('0.050313');
});

it('shows the user a price that already includes the markup', function () {
    $pricing = new Pricing(rateCard());
    $request = new GenerationRequest('a prompt', '1024x1024', 'standard');

    expect($pricing->estimate($request)->equals(
        $pricing->charge($pricing->estimatedProviderCost($request))
    ))->toBeTrue();
});

it('includes reference images in the estimate', function () {
    $pricing = new Pricing(rateCard());

    $without = $pricing->estimate(new GenerationRequest('a prompt', '1024x1024', 'standard'));
    $with = $pricing->estimate(new GenerationRequest('a prompt', '1024x1024', 'standard', [
        'https://example.com/one.png',
        'https://example.com/two.png',
    ]));

    // Two images at $0.002 is $0.004 of provider cost, or $0.005 charged.
    expect($with->minus($without)->toUsdString())->toBe('0.005000');
});

it('charges a higher quality more than a standard one', function () {
    $pricing = new Pricing(rateCard());

    $standard = $pricing->estimate(new GenerationRequest('a prompt', '1024x1024', 'standard'));
    $hd = $pricing->estimate(new GenerationRequest('a prompt', '1024x1024', 'hd'));

    expect($hd->isGreaterThan($standard))->toBeTrue();
});

it('refuses to estimate a combination it has no expectation for', function () {
    (new Pricing(rateCard()))->estimate(new GenerationRequest('a prompt', '4096x4096', 'standard'));
})->throws(InvalidArgumentException::class);

it('rejects a rate that is not a decimal string', function () {
    rateCard(['input' => '5e-6']);
})->throws(InvalidArgumentException::class);
