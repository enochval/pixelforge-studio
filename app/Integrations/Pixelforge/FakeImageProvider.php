<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge;

use App\Domain\GenerationStatus;
use App\Domain\TokenUsage;
use App\Integrations\Pixelforge\Contracts\ImageProvider;
use App\Integrations\Pixelforge\Data\GenerationSubmission;
use App\Integrations\Pixelforge\Data\ProviderGeneration;
use App\Integrations\Pixelforge\Data\SubmissionResult;
use App\Integrations\Pixelforge\Exceptions\GenerationNotFound;
use App\Integrations\Pixelforge\Exceptions\ProviderException;
use Illuminate\Support\Str;

/**
 * An in-memory provider for tests and local development.
 *
 * It honours the prompt keywords the real sandbox uses to force an outcome:
 * [fail] makes the generation fail, and [slow] leaves it processing until it
 * is completed explicitly. A scenario written against this implementation can
 * therefore be replayed against the provider without being rewritten.
 *
 * [duplicate] is not handled here. It controls webhook delivery rather than
 * the generation itself, so it belongs to the tests covering the inbox.
 *
 * This is the justification for the ImageProvider interface. Without a second
 * implementation the abstraction would be speculation; with one, the whole
 * application above the provider can be exercised with no network involved.
 */
final class FakeImageProvider implements ImageProvider
{
    /** @var array<string, ProviderGeneration> */
    private array $generations = [];

    /** @var array<int, GenerationSubmission> */
    private array $submissions = [];

    /** @var array<string, string> Idempotency key to provider generation id. */
    private array $byIdempotencyKey = [];

    private ?ProviderException $nextFailure = null;

    /** Number of submissions to fail before letting one through. */
    private int $failuresRemaining = 0;

    public function submit(GenerationSubmission $submission): SubmissionResult
    {
        $this->submissions[] = $submission;

        if ($this->failuresRemaining > 0 && $this->nextFailure !== null) {
            $this->failuresRemaining--;
            throw $this->nextFailure;
        }

        // A repeated submission with a key already seen returns the original
        // generation rather than creating a second one, which is what the
        // real header is for.
        if (isset($this->byIdempotencyKey[$submission->idempotencyKey])) {
            $existing = $this->generations[$this->byIdempotencyKey[$submission->idempotencyKey]];

            return new SubmissionResult($existing->providerGenerationId, $existing->status);
        }

        $id = 'gen_'.Str::random(16);
        $this->byIdempotencyKey[$submission->idempotencyKey] = $id;

        $this->generations[$id] = $this->outcomeFor($submission, $id);

        return new SubmissionResult($id, GenerationStatus::Queued);
    }

    /**
     * Applies the sandbox prompt keywords. A generation is queued unless the
     * prompt asks for a particular ending.
     */
    private function outcomeFor(GenerationSubmission $submission, string $id): ProviderGeneration
    {
        $prompt = $submission->request->prompt;

        if (str_contains($prompt, '[fail]')) {
            return new ProviderGeneration(
                providerGenerationId: $id,
                status: GenerationStatus::Failed,
                failureReason: 'The generation failed.',
            );
        }

        if (str_contains($prompt, '[slow]')) {
            return new ProviderGeneration(
                providerGenerationId: $id,
                status: GenerationStatus::Processing,
            );
        }

        return new ProviderGeneration(
            providerGenerationId: $id,
            status: GenerationStatus::Queued,
        );
    }

    public function fetch(string $providerGenerationId): ProviderGeneration
    {
        return $this->generations[$providerGenerationId]
            ?? throw new GenerationNotFound("No generation {$providerGenerationId}.");
    }

    // -----------------------------------------------------------------
    // Test control
    // -----------------------------------------------------------------

    /**
     * Fail the next $times submissions with $failure, then behave normally.
     */
    public function failNextSubmissions(ProviderException $failure, int $times = 1): self
    {
        $this->nextFailure = $failure;
        $this->failuresRemaining = $times;

        return $this;
    }

    /**
     * Move a generation to a successful outcome, as a webhook or a poll would.
     */
    public function complete(string $providerGenerationId, ?TokenUsage $usage = null): ProviderGeneration
    {
        return $this->generations[$providerGenerationId] = new ProviderGeneration(
            providerGenerationId: $providerGenerationId,
            status: GenerationStatus::Succeeded,
            usage: $usage ?? new TokenUsage(inputTokens: 200, outputTokens: 1_056),
            imageUrl: "https://images.test/{$providerGenerationId}.png",
        );
    }

    public function fail(string $providerGenerationId, string $reason = 'The generation failed.'): ProviderGeneration
    {
        return $this->generations[$providerGenerationId] = new ProviderGeneration(
            providerGenerationId: $providerGenerationId,
            status: GenerationStatus::Failed,
            failureReason: $reason,
        );
    }

    public function advanceToProcessing(string $providerGenerationId): ProviderGeneration
    {
        return $this->generations[$providerGenerationId] = new ProviderGeneration(
            providerGenerationId: $providerGenerationId,
            status: GenerationStatus::Processing,
        );
    }

    /**
     * @return array<int, GenerationSubmission>
     */
    public function submissions(): array
    {
        return $this->submissions;
    }

    public function submissionCount(): int
    {
        return count($this->submissions);
    }

    public function generationCount(): int
    {
        return count($this->generations);
    }
}
