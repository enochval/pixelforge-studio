<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge\Exceptions;

/**
 * The request did not complete: a connection failure or a timeout.
 *
 * For a submission this is an unknown outcome rather than a failed one. The
 * request may have been received and acted upon, which is why submissions
 * carry an idempotency key and why a timeout never leads to a blind resubmit.
 */
final class ProviderUnavailable extends ProviderException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
