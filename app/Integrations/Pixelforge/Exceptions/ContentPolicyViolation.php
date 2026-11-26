<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge\Exceptions;

/**
 * The prompt was rejected. Nothing was created and nothing was billed.
 *
 * Separate from a validation failure because the request was well formed — the
 * content was refused, not the shape of it. The distinction is what the user
 * sees: a prompt they can rewrite, rather than an error in the application.
 */
final class ContentPolicyViolation extends ProviderException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
