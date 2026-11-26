<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge\Data;

use App\Domain\GenerationStatus;
use App\Domain\TokenUsage;
use DateTimeImmutable;

/**
 * The provider's view of one generation, however it was obtained.
 *
 * A webhook payload and a polled response are translated into this same shape,
 * because both carry the same thing: the provider sends the full generation
 * object inside every event. So the code that applies a state change does not
 * know or care which arrived, and reconciliation and webhook handling share one
 * implementation instead of two that drift.
 */
final readonly class ProviderGeneration
{
    /**
     * @param  array<string, string>  $rates  The rates this generation was fixed at
     * @param  array<string, string>  $metadata  What was sent on submission, echoed back
     */
    public function __construct(
        public string $providerGenerationId,
        public GenerationStatus $status,
        public ?TokenUsage $usage = null,
        public ?string $imageUrl = null,
        public ?DateTimeImmutable $imageUrlExpiresAt = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public array $rates = [],
        public array $metadata = [],
        public ?DateTimeImmutable $completedAt = null,
    ) {}

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function succeeded(): bool
    {
        return $this->status === GenerationStatus::Succeeded;
    }

    /**
     * Our own identifier, sent as metadata on submission and returned on the
     * generation and on every webhook.
     *
     * This is what lets a delivery be matched to a row before the provider's
     * own identifier has been recorded — the documented case where a webhook
     * arrives before the create request has returned.
     */
    public function clientReference(): ?string
    {
        return $this->metadata['generation_id'] ?? null;
    }
}
