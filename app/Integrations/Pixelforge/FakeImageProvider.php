<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge;

use App\Domain\GenerationStatus;
use App\Domain\TokenUsage;
use App\Integrations\Pixelforge\Contracts\ImageProvider;
use App\Integrations\Pixelforge\Data\GenerationSubmission;
use App\Integrations\Pixelforge\Data\ProviderGeneration;
use App\Integrations\Pixelforge\Data\SubmissionResult;
use App\Integrations\Pixelforge\Exceptions\ContentPolicyViolation;
use App\Integrations\Pixelforge\Exceptions\GenerationNotFound;
use App\Integrations\Pixelforge\Exceptions\ProviderException;
use App\Integrations\Pixelforge\Exceptions\ProviderOverloaded;
use DateTimeImmutable;
use Illuminate\Support\Str;

/**
 * An in-memory provider for tests and local development.
 *
 * It honours the prompt keywords the real provider uses to force an outcome,
 * so a scenario written against this implementation can be replayed against the
 * provider without being rewritten:
 *
 *   [blocked]    rejected at submission, nothing created
 *   [overloaded] rejected at submission, nothing created
 *   [fail]       accepted, then fails while processing
 *   [slow]       accepted and left processing until completed explicitly
 *
 * Two of the provider's keywords have no counterpart here, for the same reason.
 * [succeed] suppresses the random failures the real provider injects, and
 * [duplicate] makes it deliver every event twice. This implementation does
 * neither on its own: a test states the outcome it wants rather than inheriting
 * one, and duplicate delivery is driven explicitly by the tests covering the
 * inbox. A fake that failed at random would make the suite flaky without
 * testing anything the explicit cases do not already cover.
 *
 * This is the justification for the ImageProvider interface. Without a second
 * implementation the abstraction would be speculation; with one, the whole
 * application above the provider can be exercised with no network involved.
 */
final class FakeImageProvider implements ImageProvider
{
    /**
     * The published rates, as the pricing endpoint returns them. Held here so a
     * test exercises the same parsing path as production.
     */
    public const RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    /** @var array<string, ProviderGeneration> */
    private array $generations = [];

    /** @var array<int, GenerationSubmission> */
    private array $submissions = [];

    /** @var array<string, string> Idempotency key to provider generation id. */
    private array $byIdempotencyKey = [];

    private ?ProviderException $nextFailure = null;

    /** Number of submissions to fail before letting one through. */
    private int $failuresRemaining = 0;

    private int $pricingCalls = 0;

    public function submit(GenerationSubmission $submission): SubmissionResult
    {
        $this->submissions[] = $submission;

        if ($this->failuresRemaining > 0 && $this->nextFailure !== null) {
            $this->failuresRemaining--;
            throw $this->nextFailure;
        }

        $prompt = $submission->request->prompt;

        // Rejected at submission: nothing is created and nothing is billed.
        if (Str::contains($prompt, '[blocked]', ignoreCase: true)) {
            throw new ContentPolicyViolation('The prompt was rejected.');
        }

        if (Str::contains($prompt, '[overloaded]', ignoreCase: true)) {
            throw new ProviderOverloaded('Pixelforge is temporarily overloaded.', retryAfterSeconds: 2);
        }

        // A repeated submission under a key already seen returns the existing
        // generation as it is now rather than creating a second one, which is
        // what the real Idempotency-Key header does.
        if (isset($this->byIdempotencyKey[$submission->idempotencyKey])) {
            $existing = $this->generations[$this->byIdempotencyKey[$submission->idempotencyKey]];

            return new SubmissionResult(
                providerGenerationId: $existing->providerGenerationId,
                status: $existing->status,
                rates: self::RATES,
                replayed: true,
            );
        }

        $id = 'gen_'.Str::upper(Str::random(26));
        $this->byIdempotencyKey[$submission->idempotencyKey] = $id;
        $this->generations[$id] = $this->outcomeFor($submission, $id);

        return new SubmissionResult(
            providerGenerationId: $id,

            // Always queued on acceptance. A keyword that makes a generation
            // fail does so while processing, not at submission, so the create
            // response looks the same either way.
            status: GenerationStatus::Queued,
            rates: self::RATES,
        );
    }

    /**
     * The state a newly accepted generation settles into, before anything polls
     * or any webhook is applied.
     */
    private function outcomeFor(GenerationSubmission $submission, string $id): ProviderGeneration
    {
        $prompt = $submission->request->prompt;
        $metadata = ['generation_id' => $submission->clientReference];

        if (Str::contains($prompt, '[fail]', ignoreCase: true)) {
            return new ProviderGeneration(
                providerGenerationId: $id,
                status: GenerationStatus::Failed,
                failureCode: 'generation_failed',
                failureMessage: 'The model could not produce an image for this prompt.',
                rates: self::RATES,
                metadata: $metadata,
                completedAt: new DateTimeImmutable,
            );
        }

        return new ProviderGeneration(
            providerGenerationId: $id,
            status: Str::contains($prompt, '[slow]', ignoreCase: true)
                ? GenerationStatus::Processing
                : GenerationStatus::Queued,
            rates: self::RATES,
            metadata: $metadata,
        );
    }

    public function fetch(string $providerGenerationId): ProviderGeneration
    {
        return $this->generations[$providerGenerationId]
            ?? throw new GenerationNotFound("No generation {$providerGenerationId}.");
    }

    /**
     * @return array<string, mixed>
     */
    public function pricing(): array
    {
        $this->pricingCalls++;

        /** @var array<string, mixed> $published */
        $published = config('pixelforge.pricing.fallback');

        return $published;
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
     *
     * The default usage is a plausible completion of a medium quality square
     * image: an exact output count from the published table, and text and
     * reasoning counts within their published bounds.
     */
    public function complete(string $providerGenerationId, ?TokenUsage $usage = null): ProviderGeneration
    {
        return $this->generations[$providerGenerationId] = new ProviderGeneration(
            providerGenerationId: $providerGenerationId,
            status: GenerationStatus::Succeeded,
            usage: $usage ?? new TokenUsage(
                inputTextTokens: 12,
                inputImageTokens: 0,
                outputTokens: 1_100,
                reasoningTokens: 840,
            ),
            imageUrl: "https://images.test/{$providerGenerationId}.png",
            imageUrlExpiresAt: new DateTimeImmutable('+24 hours'),
            rates: self::RATES,
            metadata: $this->metadataFor($providerGenerationId),
            completedAt: new DateTimeImmutable,
        );
    }

    public function fail(string $providerGenerationId, string $code = 'generation_failed'): ProviderGeneration
    {
        return $this->generations[$providerGenerationId] = new ProviderGeneration(
            providerGenerationId: $providerGenerationId,
            status: GenerationStatus::Failed,
            failureCode: $code,
            failureMessage: 'The generation failed.',
            rates: self::RATES,
            metadata: $this->metadataFor($providerGenerationId),
            completedAt: new DateTimeImmutable,
        );
    }

    public function advanceToProcessing(string $providerGenerationId): ProviderGeneration
    {
        return $this->generations[$providerGenerationId] = new ProviderGeneration(
            providerGenerationId: $providerGenerationId,
            status: GenerationStatus::Processing,
            rates: self::RATES,
            metadata: $this->metadataFor($providerGenerationId),
        );
    }

    /**
     * The metadata already recorded against a generation.
     *
     * A state change carries it forward rather than dropping it, because the
     * real provider sends the full generation object on every event and that
     * object always includes the metadata the submission set.
     *
     * @return array<string, string>
     */
    private function metadataFor(string $providerGenerationId): array
    {
        $existing = $this->generations[$providerGenerationId] ?? null;

        return $existing === null ? [] : $existing->metadata;
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

    public function pricingCalls(): int
    {
        return $this->pricingCalls;
    }
}
