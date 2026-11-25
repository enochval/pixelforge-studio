<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge\Exceptions;

/**
 * The provider has no record of the generation. Raised when polling an
 * identifier the provider does not recognise.
 */
final class GenerationNotFound extends ProviderException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
