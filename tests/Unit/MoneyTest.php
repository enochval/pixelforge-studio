<?php

declare(strict_types=1);

use App\Domain\Money;

it('converts decimal rate strings to micro-dollars without losing precision', function (string $usd, int $expectedMicros) {
    expect(Money::fromUsd($usd)->micros)->toBe($expectedMicros);
})->with([
    'whole dollar' => ['1', 1_000_000],
    'one cent' => ['0.01', 10_000],
    'one hundredth of a cent' => ['0.0001', 100],
    'one micro-dollar' => ['0.000001', 1],
    'a published per-token rate' => ['0.000040', 40],
    'a long decimal' => ['12.345678', 12_345_678],
    'zero' => ['0', 0],
    'zero with decimals' => ['0.000000', 0],
    'a negative amount' => ['-0.25', -250_000],
]);

it('rounds half away from zero when a value falls below one micro-dollar', function (string $usd, int $expectedMicros) {
    expect(Money::fromUsd($usd)->micros)->toBe($expectedMicros);
})->with([
    'exactly half rounds up' => ['0.0000005', 1],
    'just under half rounds down' => ['0.0000004', 0],
    'just over half rounds up' => ['0.0000006', 1],
    'negative half rounds away from zero' => ['-0.0000005', -1],
]);

it('rejects values that are not decimal amounts', function (string $input) {
    Money::fromUsd($input);
})->with(['1e-6', '0.00.1', 'free', '', '1,000'])
    ->throws(InvalidArgumentException::class);

it('adds and subtracts exactly', function () {
    $tenth = Money::fromUsd('0.1');
    $fifth = Money::fromUsd('0.2');

    // The sum that floating point gets wrong: 0.1 + 0.2 !== 0.3
    expect($tenth->plus($fifth)->equals(Money::fromUsd('0.3')))->toBeTrue()
        ->and($tenth->plus($fifth)->minus($fifth)->equals($tenth))->toBeTrue();
});

it('applies a markup in basis points', function (int $micros, int $basisPoints, int $expected) {
    expect(Money::fromMicros($micros)->withMarkup($basisPoints)->micros)->toBe($expected);
})->with([
    'twenty five per cent of a round number' => [100, 2_500, 125],
    'no markup leaves the amount alone' => [1_234, 0, 1_234],
    'a fraction rounding down' => [1, 2_500, 1],       // 1.25 -> 1
    'a fraction rounding up' => [2, 2_500, 3],         // 2.50 -> 3
    'a fraction rounding up again' => [3, 2_500, 4],   // 3.75 -> 4
    'zero stays zero' => [0, 2_500, 0],
    'a realistic generation cost' => [43_120, 2_500, 53_900],
]);

it('refuses a negative markup', function () {
    Money::fromMicros(100)->withMarkup(-100);
})->throws(InvalidArgumentException::class);

it('loses nothing by marking up a full-precision cost rather than a rounded one', function () {
    // A cost of $0.0431195, marked up by 25%.
    $exact = Money::fromUsd('0.0431195');

    // Rounding to the nearest cent first discards almost the whole amount and
    // then multiplies the error by the markup. This is the failure the micro-
    // dollar representation exists to prevent.
    $roundedToCents = Money::fromUsd('0.04');

    expect($exact->withMarkup(2_500)->toUsdString())->toBe('0.053900')
        ->and($roundedToCents->withMarkup(2_500)->toUsdString())->toBe('0.050000')
        ->and($exact->withMarkup(2_500)->micros)->not->toBe($roundedToCents->withMarkup(2_500)->micros);
});

it('renders an exact decimal string for display and reconciliation', function (int $micros, string $expected) {
    expect(Money::fromMicros($micros)->toUsdString())->toBe($expected);
})->with([
    [1_000_000, '1.000000'],
    [3_200, '0.003200'],
    [1, '0.000001'],
    [0, '0.000000'],
]);

it('is immutable', function () {
    $original = Money::fromUsd('1.00');
    $original->plus(Money::fromUsd('1.00'))->withMarkup(2_500);

    expect($original->micros)->toBe(1_000_000);
});

it('rejects amounts large enough to risk integer overflow', function () {
    Money::fromMicros(PHP_INT_MAX);
})->throws(InvalidArgumentException::class);

it('compares amounts', function () {
    $small = Money::fromUsd('0.01');
    $large = Money::fromUsd('0.02');

    expect($large->isGreaterThan($small))->toBeTrue()
        ->and($small->isGreaterThan($large))->toBeFalse()
        ->and($small->isGreaterThanOrEqualTo($small))->toBeTrue()
        ->and(Money::zero()->isZero())->toBeTrue()
        ->and(Money::fromUsd('-0.01')->isNegative())->toBeTrue();
});
