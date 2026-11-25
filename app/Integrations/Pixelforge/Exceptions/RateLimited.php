<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge\Exceptions;

/**
 * Too many requests in a window. The provider usually states how long to wait,
 * and that instruction is preferred over any backoff of our own.
 */
final class RateLimited extends ProviderException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
