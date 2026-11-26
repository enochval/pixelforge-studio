<?php

declare(strict_types=1);

use App\Domain\Money;

it('converts decimal USD strings to nanodollars without losing precision', function (string $usd, int $expectedNanos) {
    expect(Money::fromUsd($usd)->nanos)->toBe($expectedNanos);
})->with([
    'a whole dollar' => ['1', 1_000_000_000],
    'a dollar and a half' => ['1.50', 1_500_000_000],
    'one cent' => ['0.01', 10_000_000],
    'one nanodollar' => ['0.000000001', 1],
    'a published cost string' => ['0.169080000', 169_080_000],
    'the documented worked example' => ['0.373875000', 373_875_000],
    'zero' => ['0', 0],
    'a negative amount' => ['-0.054560000', -54_560_000],
]);

it('converts rates quoted per million tokens into an exact integer per token', function (string $perMillion, int $expectedNanos) {
    expect(Money::fromUsdPerMillionTokens($perMillion)->nanos)->toBe($expectedNanos);
})->with([
    // The four published rates. Each one lands on a whole number of nanos per
    // token, which is what makes a provider cost exact rather than rounded.
    'input text' => ['5.00', 5_000],
    'input image' => ['10.00', 10_000],
    'output' => ['40.00', 40_000],

    // The rate that rules out micro-dollars: 12.5 micro-dollars per token.
    'reasoning' => ['12.50', 12_500],

    'the smallest step the provider quotes' => ['0.01', 10],
]);

it('refuses a negative rate', function () {
    Money::fromUsdPerMillionTokens('-5.00');
})->throws(InvalidArgumentException::class);

it('does not pass decimal strings through a float', function () {
    // (int) (0.000000001 * 1_000_000_000) evaluates to 0 on some platforms
    // rather than 1, because the literal has no exact binary representation.
    // An error of that shape is invisible on one generation and material
    // across thousands, so every conversion goes through bcmath.
    expect(Money::fromUsd('0.000000001')->nanos)->toBe(1)
        ->and(Money::fromUsd('0.000000003')->nanos)->toBe(3);
});

it('rounds half away from zero below one nanodollar', function (string $usd, int $expectedNanos) {
    expect(Money::fromUsd($usd)->nanos)->toBe($expectedNanos);
})->with([
    'rounds a half up' => ['0.0000000015', 2],
    'rounds below a half down' => ['0.0000000014', 1],
    'rounds a negative half away from zero' => ['-0.0000000015', -2],
]);

it('rejects anything that is not a decimal amount', function (string $input) {
    Money::fromUsd($input);
})->with([
    'a word' => ['free'],
    'scientific notation' => ['1e-9'],
    'a currency symbol' => ['$1.00'],
    'a thousands separator' => ['1,000.00'],
    'an empty string' => [''],
])->throws(InvalidArgumentException::class);

it('applies a markup in basis points', function (int $nanos, int $basisPoints, int $expected) {
    expect(Money::fromNanos($nanos)->withMarkup($basisPoints)->nanos)->toBe($expected);
})->with([
    'no markup leaves the amount alone' => [1_000_000, 0, 1_000_000],
    'twenty five per cent' => [1_000_000, 2_500, 1_250_000],
    'one hundred per cent doubles it' => [1_000_000, 10_000, 2_000_000],
    'a real provider cost at twenty five per cent' => [54_560_000, 2_500, 68_200_000],
    'rounds half away from zero' => [3, 2_500, 4],
    'zero stays zero' => [0, 2_500, 0],
]);

it('refuses a negative markup', function () {
    Money::fromNanos(100)->withMarkup(-100);
})->throws(InvalidArgumentException::class);

it('loses nothing to rounding that a cent-based amount would', function () {
    // The reasoning rate is 12.5 micro-dollars per token. Held in cents, or
    // even in micro-dollars, a single reasoning token rounds to a figure that
    // is wrong before any markup is applied — and the markup then multiplies
    // the error. This is the whole reason for the unit.
    $oneReasoningToken = Money::fromUsdPerMillionTokens('12.50');

    expect($oneReasoningToken->nanos)->toBe(12_500)
        ->and($oneReasoningToken->toUsdString())->toBe('0.000012500');

    $eightThousandTokens = $oneReasoningToken->multipliedBy(8_000);

    expect($eightThousandTokens->toUsdString())->toBe('0.100000000');
});

it('renders an exact decimal string for display and reconciliation', function (int $nanos, string $expected) {
    expect(Money::fromNanos($nanos)->toUsdString())->toBe($expected);
})->with([
    // Nine decimal places by default, matching the precision the provider
    // reports costs at, so a recorded cost and a reported one compare as
    // strings without either being reformatted.
    'a dollar' => [1_000_000_000, '1.000000000'],
    'a published daily total' => [169_080_000, '0.169080000'],
    'a single nanodollar' => [1, '0.000000001'],
    'zero' => [0, '0.000000000'],
]);

it('is immutable', function () {
    $original = Money::fromUsd('1.00');
    $marked = $original->withMarkup(2_500);

    expect($original->nanos)->toBe(1_000_000_000)
        ->and($marked->nanos)->toBe(1_250_000_000);
});

it('refuses an amount large enough to risk overflowing the markup', function () {
    Money::fromNanos(PHP_INT_MAX);
})->throws(InvalidArgumentException::class);
