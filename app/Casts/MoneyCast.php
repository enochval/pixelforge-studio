<?php

declare(strict_types=1);

namespace App\Casts;

use App\Domain\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Converts a bigint column of micro-dollars to and from Money.
 *
 * The point is that no monetary value exists as a bare integer or a float
 * anywhere above the database. Reading a column gives a Money; writing one
 * accepts a Money and nothing else, so arithmetic cannot be performed on a
 * raw amount by accident.
 *
 * The set type is deliberately `mixed` rather than `Money|null`: the guard
 * below exists precisely because anything can reach it at runtime, and
 * narrowing the annotation would make that check look unreachable.
 *
 * @implements CastsAttributes<Money|null, mixed>
 */
final class MoneyCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        return $value === null ? null : Money::fromMicros((int) $value);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof Money) {
            throw new InvalidArgumentException(
                sprintf('%s must be set from a Money instance, %s given.', $key, get_debug_type($value)),
            );
        }

        return $value->micros;
    }
}
