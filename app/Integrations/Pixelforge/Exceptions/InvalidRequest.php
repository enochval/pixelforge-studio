<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge\Exceptions;

/**
 * The provider rejected the request as malformed or unacceptable.
 *
 * Never retried. Repeating a request the provider has already refused only
 * adds load and delays the failure the user is waiting to be told about.
 */
final class InvalidRequest extends ProviderException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
