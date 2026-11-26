<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge\Exceptions;

/**
 * Too many generations queued or processing at once — three, per the provider's
 * published account limit.
 *
 * Arrives as 429 with Retry-After, the same status a rate limit and an
 * exhausted daily quota use, and is told apart from them by the error code the
 * provider sends. Distinct from a rate limit in what resolves it: waiting does
 * not help unless one of our own in-flight generations finishes first. That is
 * why a job seeing this is released back to the queue rather than made to
 * sleep. See ConcurrencyLimiter.
 */
final class ConcurrencyLimited extends ProviderException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
