<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge\Exceptions;

/**
 * The idempotency key was already used with different parameters.
 *
 * This one is not a provider problem. The key is stored on the generation row
 * before the first attempt and every retry presents that same key, so the
 * provider can only report this if the parameters sent under it changed — which
 * means a generation's prompt, size or quality was mutated after submission.
 *
 * Never retried: repeating it reproduces the same conflict. It is surfaced as a
 * failure rather than absorbed, because quietly minting a fresh key would turn
 * a bug in this application into a second generation the user pays for.
 */
final class IdempotencyKeyReused extends ProviderException
{
    public function isRetryable(): bool
    {
        return false;
    }
}
