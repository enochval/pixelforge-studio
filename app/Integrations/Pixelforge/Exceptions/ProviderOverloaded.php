<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge\Exceptions;

/**
 * The provider is failing under load rather than rejecting the request, so a
 * later attempt may well succeed. How long to wait is decided by RetryPolicy.
 */
final class ProviderOverloaded extends ProviderException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
