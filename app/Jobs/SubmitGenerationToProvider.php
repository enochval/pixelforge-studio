<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\GenerationRequest;
use App\Domain\GenerationStatus;
use App\Integrations\Pixelforge\Contracts\ImageProvider;
use App\Integrations\Pixelforge\Data\GenerationSubmission;
use App\Integrations\Pixelforge\Exceptions\ConcurrencyLimited;
use App\Integrations\Pixelforge\Exceptions\ProviderException;
use App\Models\Generation;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use Random\Randomizer;
use Throwable;

/**
 * Sends one generation to the provider.
 *
 * Kept out of the web request so that a slow provider makes a generation slow
 * rather than making the application slow, and so that retrying is the
 * queue's concern rather than the user's.
 */
final class SubmitGenerationToProvider implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $generationId) {}

    /**
     * Stops two workers submitting the same generation at once. The
     * idempotency key would make a duplicate harmless, but not reaching the
     * provider twice is cheaper than relying on it to notice.
     *
     * A blocked duplicate is discarded rather than released: whichever job
     * holds the lock is doing exactly the work this one would repeat, so
     * returning it to the queue only schedules the same no-op again.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->generationId))->dontRelease()];
    }

    /**
     * Growing waits between attempts, in seconds.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [1, 5, 15, 60, 300];
    }

    /**
     * An upper bound on the whole sequence, independent of the attempt count.
     * A submission still failing after ten minutes is unlikely to succeed on a
     * later attempt, and the user is owed an outcome either way.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    public function handle(ImageProvider $provider): void
    {
        $generation = Generation::find($this->generationId);

        if ($generation === null) {
            return;
        }

        // Already submitted, or already resolved by a webhook or the
        // reconciler while this attempt was queued. Running again would send a
        // second request for work the provider has already accepted.
        if ($generation->provider_generation_id !== null || $generation->isTerminal()) {
            return;
        }

        try {
            $result = $provider->submit(new GenerationSubmission(
                request: new GenerationRequest(
                    prompt: $generation->prompt,
                    size: $generation->size,
                    quality: $generation->quality,
                    referenceImageUrls: $generation->reference_image_urls,
                ),

                // Stored on the row rather than generated here, so every
                // attempt at this generation presents the same key. A key
                // minted per attempt would make retrying a way to create a
                // second generation rather than a way to avoid one.
                idempotencyKey: $generation->idempotency_key,
                clientReference: $generation->id,
            ));
        } catch (ConcurrencyLimited) {
            // Nothing is wrong with the request; there is simply no slot. The
            // worker is freed for other work and this generation is tried
            // again shortly, spread out so the retries do not arrive together.
            $this->release((new Randomizer)->getInt(1, 5));

            return;
        } catch (ProviderException $e) {
            if ($e->isRetryable()) {
                // Let the queue count the attempt and apply the backoff above.
                throw $e;
            }

            // The provider will not accept this request however often it is
            // repeated, so the user is told now rather than in ten minutes.
            $this->markFailed($generation, $e);

            return;
        }

        $generation->update([
            'provider_generation_id' => $result->providerGenerationId,
            'status' => $result->status,

            // The rates the provider fixed for this generation. Recorded now
            // because the published table can change before the generation
            // completes, and the charge has to be computed from the rates this
            // one was created under.
            'rates' => $result->rates,

            'submitted_at' => now(),
        ]);
    }

    /**
     * Called once the attempts or the time limit are exhausted.
     *
     * The generation is closed as failed and nothing is charged. A job that
     * gave up quietly would leave a row stuck in `creating` and a user
     * watching a status that will never change.
     */
    public function failed(?Throwable $exception): void
    {
        $generation = Generation::find($this->generationId);

        if ($generation === null || $generation->isTerminal()) {
            return;
        }

        $this->markFailed($generation, $exception);
    }

    /**
     * Close a generation that will never be submitted.
     *
     * The failure code is taken from the exception class rather than from the
     * provider's message, so the reason a generation failed stays groupable
     * once there are thousands of them. The message is what the user reads.
     */
    private function markFailed(Generation $generation, ?Throwable $cause): void
    {
        $generation->update([
            'status' => GenerationStatus::Failed,
            'failure_code' => $cause === null
                ? 'submission_failed'
                : Str::snake(class_basename($cause)),
            'failure_message' => $cause?->getMessage() ?? 'The generation could not be submitted.',
            'completed_at' => now(),
        ]);
    }
}
