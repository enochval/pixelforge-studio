<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;
use JsonSerializable;

/**
 * A monetary amount, held as an integer number of micro-dollars.
 *
 * 1 USD = 1,000,000 micros, so the smallest representable amount is one
 * ten-thousandth of a cent. Provider costs arrive in fractions of a cent, and
 * this gives four decimal places of headroom beyond the cent without any
 * intermediate value needing to be rounded.
 *
 * Nothing in this class uses a float. Converting a decimal rate string with
 * (int) ($rate * 1_000_000) truncates on some platforms — 0.0001 becomes 99
 * micros rather than 100 — and an error of that shape is invisible on a single
 * generation and material across thousands. All string-to-integer conversion
 * goes through bcmath.
 */
final readonly class Money implements JsonSerializable
{
    public const MICROS_PER_USD = 1_000_000;

    /**
     * Guards against silent integer overflow. PHP integers are 64-bit, and
     * applying a markup multiplies by up to five figures before dividing, so
     * amounts are capped well below the point where that could wrap.
     */
    private const MAX_MICROS = 1_000_000_000_000_000;

    private function __construct(public int $micros)
    {
        if (abs($micros) > self::MAX_MICROS) {
            throw new InvalidArgumentException("Amount out of range: {$micros} micros.");
        }
    }

    public static function fromMicros(int $micros): self
    {
        return new self($micros);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Build from a decimal USD string, rounding half away from zero to the
     * nearest micro-dollar. Accepts the rate strings published in the provider
     * documentation without passing them through a float.
     */
    public static function fromUsd(string $usd): self
    {
        if (! preg_match('/^-?\d+(\.\d+)?$/', $usd)) {
            throw new InvalidArgumentException("Not a decimal amount: {$usd}");
        }

        $scaled = bcmul($usd, (string) self::MICROS_PER_USD, 12);
        $half = bccomp($scaled, '0', 12) >= 0 ? '0.5' : '-0.5';

        // bcadd at scale 0 truncates toward zero, so adding a half first turns
        // truncation into half-away-from-zero rounding.
        return new self((int) bcadd($scaled, $half, 0));
    }

    public function plus(self $other): self
    {
        return new self($this->micros + $other->micros);
    }

    public function minus(self $other): self
    {
        return new self($this->micros - $other->micros);
    }

    public function multipliedBy(int $factor): self
    {
        return new self($this->micros * $factor);
    }

    /**
     * Apply a markup expressed in basis points: 2500 bps is 25%.
     *
     * Rounds half away from zero, once. The input is already an exact integer
     * number of micros, so this is the only rounding the charged amount is
     * subject to.
     */
    public function withMarkup(int $basisPoints): self
    {
        if ($basisPoints < 0) {
            throw new InvalidArgumentException("Markup cannot be negative: {$basisPoints} bps.");
        }

        $scaled = $this->micros * (10_000 + $basisPoints);

        return new self($scaled >= 0
            ? intdiv($scaled + 5_000, 10_000)
            : -intdiv(-$scaled + 5_000, 10_000));
    }

    public function isZero(): bool
    {
        return $this->micros === 0;
    }

    public function isNegative(): bool
    {
        return $this->micros < 0;
    }

    public function equals(self $other): bool
    {
        return $this->micros === $other->micros;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->micros > $other->micros;
    }

    public function isGreaterThanOrEqualTo(self $other): bool
    {
        return $this->micros >= $other->micros;
    }

    /**
     * Exact decimal representation, for display and for comparison against the
     * provider's own reporting. Never used to perform arithmetic.
     */
    public function toUsdString(int $decimals = 6): string
    {
        return bcdiv((string) $this->micros, (string) self::MICROS_PER_USD, $decimals);
    }

    public function jsonSerialize(): int
    {
        return $this->micros;
    }
}
