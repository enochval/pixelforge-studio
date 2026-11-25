<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge\Data;

use App\Domain\GenerationRequest;

/**
 * Everything needed to submit one generation.
 *
 * The idempotency key and our own identifier are carried alongside the user's
 * request rather than inside it: the request describes what was asked for, and
 * these two describe how this particular attempt is to be identified.
 */
final readonly class GenerationSubmission
{
    public function __construct(
        public GenerationRequest $request,
        public string $idempotencyKey,
        public string $clientReference,
    ) {}
}
