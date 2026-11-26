<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge;

use App\Domain\GenerationStatus;
use App\Domain\TokenUsage;
use App\Integrations\Pixelforge\Data\ProviderGeneration;
use App\Integrations\Pixelforge\Exceptions\InvalidRequest;
use DateTimeImmutable;

/**
 * Reads the provider's generation object into this application's own type.
 *
 * A class of its own, rather than a method on the HTTP client, because the same
 * object arrives by two routes: in the body of a polled response, and nested
 * inside every webhook event. Both are translated here, so a webhook and a poll
 * cannot be understood differently — and the webhook path does not have to
 * depend on an HTTP client it never calls.
 *
 * This is the only place that knows the provider's field names. A change to
 * their contract is a change to this file.
 */
final readonly class GenerationTranslator
{
    /**
     * @param  array<string, mixed>  $body  A generation object
     */
    public function fromPayload(array $body): ProviderGeneration
    {
        /** @var array<string, mixed>|null $usage */
        $usage = $body['usage'] ?? null;

        /** @var array<string, string> $rates */
        $rates = $body['rates'] ?? [];

        /** @var array<string, string> $metadata */
        $metadata = $body['metadata'] ?? [];

        /** @var array<string, string>|null $error */
        $error = $body['error'] ?? null;

        return new ProviderGeneration(
            providerGenerationId: (string) $body['id'],
            status: $this->status((string) $body['status']),

            // Null until the generation succeeds. Failed generations are never
            // billed, so there is nothing to price.
            usage: $usage === null ? null : TokenUsage::fromProviderPayload($usage),

            imageUrl: isset($body['image_url']) ? (string) $body['image_url'] : null,
            imageUrlExpiresAt: $this->timestamp($body['image_url_expires_at'] ?? null),
            failureCode: $error['code'] ?? null,
            failureMessage: $error['message'] ?? null,
            rates: $rates,
            metadata: $metadata,
            completedAt: $this->timestamp($body['completed_at'] ?? null),
        );
    }

    /**
     * The provider publishes exactly four statuses.
     *
     * An unrecognised one is raised rather than guessed at. Mapping it onto a
     * terminal state would record an outcome the provider never reported, and
     * charge for it.
     */
    public function status(string $status): GenerationStatus
    {
        return match ($status) {
            'queued' => GenerationStatus::Queued,
            'processing' => GenerationStatus::Processing,
            'succeeded' => GenerationStatus::Succeeded,
            'failed' => GenerationStatus::Failed,
            default => throw new InvalidRequest("Unrecognised provider status: {$status}"),
        };
    }

    private function timestamp(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== ''
            ? new DateTimeImmutable($value)
            : null;
    }
}
