<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge\Data;

use App\Domain\GenerationStatus;

/**
 * What the provider returned when it accepted a submission.
 */
final readonly class SubmissionResult
{
    public function __construct(
        public string $providerGenerationId,
        public GenerationStatus $status,
    ) {}
}
