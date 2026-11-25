<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge\Data;

use App\Domain\GenerationStatus;
use App\Domain\TokenUsage;

/**
 * The provider's view of one generation, however it was obtained.
 *
 * A webhook payload and a polled response are translated into this same shape,
 * so the code that applies a state change does not know or care which arrived.
 * Two paths producing two shapes is how they come to disagree.
 */
final readonly class ProviderGeneration
{
    public function __construct(
        public string $providerGenerationId,
        public GenerationStatus $status,
        public ?TokenUsage $usage = null,
        public ?string $imageUrl = null,
        public ?string $failureReason = null,
    ) {}

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function succeeded(): bool
    {
        return $this->status === GenerationStatus::Succeeded;
    }
}
