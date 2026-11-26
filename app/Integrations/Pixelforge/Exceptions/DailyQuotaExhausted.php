<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge\Exceptions;

/**
 * The account's daily generation quota is used up.
 *
 * Reported as 429, like a rate limit and a concurrency limit, but it is neither
 * — and this is why failures are typed by what the provider said rather than by
 * status code. The quota resets at UTC midnight, which can be hours away, so
 * repeating the request within a job's lifetime cannot succeed. Treated as
 * retryable it would consume every attempt and every backoff interval, then
 * fail the generation anyway, having spent each retry against the per-minute
 * request allowance that other work needs.
 *
 * So it is not retryable. The generation is closed and the user is told that
 * capacity has run out for the day, which is a true and actionable answer
 * rather than a silent ten-minute wait ending in a generic failure.
 */
final class DailyQuotaExhausted extends ProviderException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
