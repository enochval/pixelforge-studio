<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;
use JsonSerializable;

/**
 * A monetary amount, held as an integer number of nanodollars.
 *
 * 1 USD = 1,000,000,000 nanos. The unit is set by what the provider bills in:
 * costs are reported to nine decimal places and, in the documentation's words,
 * "amounts are never rounded". A cent-based or even micro-dollar amount cannot
 * hold them. Reasoning tokens bill at $12.50 per million, which is 12.5
 * micro-dollars per token — half a micro, and lost entirely at that scale.
 *
 * In nanodollars every published rate is an exact integer per token: a rate of
 * $12.50 per million tokens is exactly 12,500 nanos per token. So a provider
 * cost is computed by integer multiplication with nothing to round, and this
 * application's record of what a generation cost can be reconciled against
 * GET /v1/usage digit for digit rather than within a tolerance.
 *
 * Nothing here uses a float. Converting a rate string with
 * (int) ($rate * 1_000_000_000) truncates on some platforms, and an error of
 * that shape is invisible on one generation and material across thousands. All
 * string-to-integer conversion goes through bcmath.
 *
 * Range: a signed 64-bit integer holds about 9.2e18 nanos, or roughly $9.2
 * billion, and the cap below leaves headroom for the markup multiplication.
 */
final readonly class Money implements JsonSerializable
{
    public const NANOS_PER_USD = 1_000_000_000;

    /**
     * Nanos per token for a rate of one dollar per million tokens.
     *
     * A billionth of a dollar, a million times over: a thousand. Every rate
     * the provider publishes is quoted in that unit, so this is the whole of
     * the conversion between their form and ours.
     */
    private const NANOS_PER_TOKEN_PER_USD_PER_MILLION = 1_000;

    /**
     * Guards against silent integer overflow. Applying a markup multiplies by
     * up to five figures before dividing, so amounts are capped well below the
     * point where that could wrap. One quadrillion nanos is $1,000,000.
     */
    private const MAX_NANOS = 1_000_000_000_000_000;

    private function __construct(public int $nanos)
    {
        if (abs($nanos) > self::MAX_NANOS) {
            throw new InvalidArgumentException("Amount out of range: {$nanos} nanos.");
        }
    }

    public static function fromNanos(int $nanos): self
    {
        return new self($nanos);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Build from a decimal USD string, rounding half away from zero to the
     * nearest nanodollar. Accepts the amounts the provider publishes — rates,
     * and the nine-decimal cost strings from GET /v1/usage — without passing
     * them through a float.
     */
    public static function fromUsd(string $usd): self
    {
        if (! preg_match('/^-?\d+(\.\d+)?$/', $usd)) {
            throw new InvalidArgumentException("Not a decimal amount: {$usd}");
        }

        $scaled = bcmul($usd, (string) self::NANOS_PER_USD, 12);
        $half = bccomp($scaled, '0', 12) >= 0 ? '0.5' : '-0.5';

        // bcadd at scale 0 truncates toward zero, so adding a half first turns
        // truncation into half-away-from-zero rounding.
        return new self((int) bcadd($scaled, $half, 0));
    }

    /**
     * Convert a rate quoted in USD per million tokens — the form every
     * Pixelforge rate takes — into nanodollars for a single token.
     *
     * Exact for any rate the provider publishes. Rates are quoted to two
     * decimal places of a dollar, so the smallest step, $0.01 per million, is
     * 10 nanos per token: an integer, with two orders of magnitude to spare.
     * A rate carrying more decimals than that would round here, which is why
     * the conversion is a named operation rather than arithmetic scattered
     * through the pricing logic.
     */
    public static function fromUsdPerMillionTokens(string $usdPerMillion): self
    {
        if (! preg_match('/^\d+(\.\d+)?$/', $usdPerMillion)) {
            throw new InvalidArgumentException("Not a non-negative decimal rate: {$usdPerMillion}");
        }

        $scaled = bcmul($usdPerMillion, (string) self::NANOS_PER_TOKEN_PER_USD_PER_MILLION, 12);

        return new self((int) bcadd($scaled, '0.5', 0));
    }

    /**
     * The inverse: a per-token amount expressed as the provider quotes it, in
     * dollars per million tokens.
     *
     * Paired with the method above so that a rate read from the provider and
     * stored on a generation comes back out in the form it arrived in.
     */
    public function toUsdPerMillionTokens(): string
    {
        return bcdiv((string) $this->nanos, (string) self::NANOS_PER_TOKEN_PER_USD_PER_MILLION, 2);
    }

    public function plus(self $other): self
    {
        return new self($this->nanos + $other->nanos);
    }

    public function minus(self $other): self
    {
        return new self($this->nanos - $other->nanos);
    }

    /**
     * The same amount, the other way round.
     *
     * A named operation rather than `zero()->minus($x)` at each call site,
     * because the ledger decides the sign of an entry from its kind and that
     * decision reads better as a word than as arithmetic.
     */
    public function negated(): self
    {
        return new self(-$this->nanos);
    }

    public function multipliedBy(int $factor): self
    {
        return new self($this->nanos * $factor);
    }

    /**
     * Apply a markup expressed in basis points: 2500 bps is 25%.
     *
     * Rounds half away from zero, once. The provider's cost reaches this method
     * as an exact integer, so this is the only rounding a charged amount is
     * ever subject to.
     */
    public function withMarkup(int $basisPoints): self
    {
        if ($basisPoints < 0) {
            throw new InvalidArgumentException("Markup cannot be negative: {$basisPoints} bps.");
        }

        $scaled = $this->nanos * (10_000 + $basisPoints);

        return new self($scaled >= 0
            ? intdiv($scaled + 5_000, 10_000)
            : -intdiv(-$scaled + 5_000, 10_000));
    }

    public function isZero(): bool
    {
        return $this->nanos === 0;
    }

    public function isNegative(): bool
    {
        return $this->nanos < 0;
    }

    public function equals(self $other): bool
    {
        return $this->nanos === $other->nanos;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->nanos > $other->nanos;
    }

    public function isGreaterThanOrEqualTo(self $other): bool
    {
        return $this->nanos >= $other->nanos;
    }

    /**
     * Exact decimal representation, for display and for comparison against the
     * provider's own reporting. Never used to perform arithmetic.
     *
     * The default of nine decimals matches the precision GET /v1/usage reports
     * at, so a cost recorded here and a cost reported there are comparable as
     * strings.
     */
    public function toUsdString(int $decimals = 9): string
    {
        return bcdiv((string) $this->nanos, (string) self::NANOS_PER_USD, $decimals);
    }

    public function jsonSerialize(): int
    {
        return $this->nanos;
    }
}
