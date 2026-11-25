<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge\Exceptions;

/**
 * Too many requests in flight at once.
 *
 * Distinct from a rate limit because waiting does not help unless something
 * else finishes first, which is why a job that sees this is released back to
 * the queue rather than made to sleep. See ConcurrencyLimiter.
 */
final class ConcurrencyLimited extends ProviderException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
