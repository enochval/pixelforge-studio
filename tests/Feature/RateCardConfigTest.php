<?php

declare(strict_types=1);

use App\Domain\GenerationRequest;
use App\Domain\Pricing;
use App\Domain\RateCard;

/**
 * The arithmetic is covered in tests/Unit. These assert that the shipped
 * configuration is well formed and can actually drive it, so a malformed rate
 * fails here rather than at the moment a user is charged.
 */
it('builds a rate card from the shipped configuration', function () {
    $card = RateCard::fromConfig(config('pixelforge'));

    expect($card->markupBasisPoints)->toBe(2_500)
        ->and($card->charactersPerToken)->toBeGreaterThan(0);
});

it('can price every configured combination of size and quality', function () {
    $pricing = new Pricing(RateCard::fromConfig(config('pixelforge')));

    foreach (config('pixelforge.estimation.output_tokens') as $size => $qualities) {
        foreach (array_keys($qualities) as $quality) {
            $estimate = $pricing->estimate(new GenerationRequest('a prompt', $size, $quality));

            expect($estimate->isNegative())->toBeFalse()
                ->and($estimate->isZero())->toBeFalse();
        }
    }
});
