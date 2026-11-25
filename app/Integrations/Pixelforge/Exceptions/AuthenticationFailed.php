<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge\Exceptions;

/**
 * The API key was missing, wrong, or revoked. A configuration fault rather
 * than a transient one, so retrying cannot resolve it.
 */
final class AuthenticationFailed extends ProviderException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
