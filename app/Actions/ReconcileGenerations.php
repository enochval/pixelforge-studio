<?php

declare(strict_types=1);

namespace App\Actions;

use App\Domain\GenerationStatus;
use App\Domain\WebhookOutcome;
use App\Integrations\Pixelforge\Contracts\ImageProvider;
use App\Integrations\Pixelforge\Exceptions\GenerationNotFound;
use App\Integrations\Pixelforge\Exceptions\ProviderException;
use App\Integrations\Pixelforge\GenerationTranslator;
use App\Jobs\SubmitGenerationToProvider;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Finishes what the webhook path did not.
 *
 * The provider is explicit that webhook delivery is not guaranteed — "if every
 * attempt fails, you can still poll GET /v1/generations/{id}". So this is not a
 * precaution bolted onto a working system; it is the other half of a contract
 * that says events may simply never arrive. Without it, a lost delivery leaves
 * a generation running forever on screen and a provider cost that was never
 * charged for.
 *
 * It handles four situations, and they are genuinely different problems rather
 * than one problem seen from four angles:
 *
 *   An event arrived before the generation could be identified. No network
 *   needed — the payload is already stored, and all that was missing is the row
 *   to attach it to.
 *
 *   A generation was accepted by the provider but no terminal event arrived.
 *   Polled by its provider id and applied through the same action the webhook
 *   path uses.
 *
 *   A generation was never accepted at all, because every submission attempt
 *   failed or timed out. Resubmitted rather than polled, because there is no
 *   provider id to poll by — and safe to resubmit because the idempotency key
 *   stored on the row makes a repeat return the original generation instead of
 *   creating a second one.
 *
 *   A generation has been unresolved long enough that nothing is coming.
 *   Closed, so it stops being polled and stops claiming to be in progress.
 *
 * Nothing here charges anything directly. Applying a provider outcome is
 * ApplyProviderEvent's job, shared with the webhook path, so a reconciled
 * generation and a webhook-resolved one cannot be priced differently — and the
 * same guards apply, which is what makes the reconciler racing a late webhook
 * harmless rather than a double charge.
 */
final readonly class ReconcileGenerations
{
    public function __construct(
        private ImageProvider $provider,
        private GenerationTranslator $translator,
        private ApplyProviderEvent $apply,
    ) {}

    /**
     * How many rows one run will touch per step.
     *
     * A cap exists so that a large backlog cannot make a single run take long
     * enough to overlap the next one. What is left over is picked up a minute
     * later, in the same order, because every query is ordered oldest first.
     *
     * Orphan matching is allowed more because it makes no network calls: it
     * reads a payload already in hand, so the only limit that matters is how
     * much work to do before yielding.
     */
    private const ORPHAN_BATCH = 200;

    private const GENERATION_BATCH = 100;

    /**
     * @return array<string, int> What was done, for the command's output and
     *                            for the scheduled run's logs
     */
    public function execute(): array
    {
        // Written as statements rather than as an array literal because the
        // order is part of the behaviour, not a presentation choice.
        //
        // Orphans first: they need no network call, and an event already stored
        // is better evidence than anything a poll returns. Polling next, so a
        // generation is only abandoned after the provider has been asked about
        // it. Abandoning last, so nothing is closed in the same run that could
        // still have been resolved.
        $orphansMatched = $this->matchOrphanedEvents();
        $polled = $this->pollAcceptedGenerations();
        $resubmitted = $this->resubmitUnacceptedGenerations();
        $abandoned = $this->abandonStaleGenerations();

        return [
            'orphans_matched' => $orphansMatched,
            'polled' => $polled,
            'resubmitted' => $resubmitted,
            'abandoned' => $abandoned,
        ];
    }

    /**
     * Stored events that had no generation to attach to when they arrived.
     */
    private function matchOrphanedEvents(): int
    {
        $matched = 0;

        $orphans = WebhookEvent::query()
            ->whereNull('processed_at')
            ->where('outcome', WebhookOutcome::Orphaned)
            ->orderBy('received_at')
            ->limit(self::ORPHAN_BATCH)
            ->get();

        foreach ($orphans as $event) {
            $payload = $event->generationPayload();

            if ($payload === null) {
                continue;
            }

            $generation = $this->findGeneration($event);

            if ($generation === null) {
                // Still unidentifiable. Left as it is rather than discarded:
                // the generation may yet appear, and if it never does the row
                // is the evidence that the provider billed for work this
                // application has no record of asking for.
                continue;
            }

            $applied = $this->apply->execute($generation, $this->translator->fromPayload($payload));

            $event->update([
                'outcome' => $applied ? WebhookOutcome::Applied : WebhookOutcome::IgnoredStale,
                'processed_at' => now(),
            ]);

            $matched++;
        }

        return $matched;
    }

    /**
     * Generations the provider accepted, whose outcome never arrived.
     *
     * The ordinary case this exists for: a webhook was lost, or all six of its
     * delivery attempts failed.
     */
    private function pollAcceptedGenerations(): int
    {
        $polled = 0;

        $stale = Generation::query()
            ->whereIn('status', $this->nonTerminalValues())
            ->whereNotNull('provider_generation_id')
            ->where('submitted_at', '<=', $this->pollThreshold())
            ->orderBy('submitted_at')
            ->limit(self::GENERATION_BATCH)
            ->get();

        foreach ($stale as $generation) {
            $providerGenerationId = $generation->provider_generation_id;

            // The query above excludes null ids, so this cannot normally be
            // reached. It is checked anyway because the alternative to a cheap
            // skip here is a type error inside a scheduled command, which fails
            // silently every minute until someone reads the log.
            if ($providerGenerationId === null) {
                continue;
            }

            try {
                $current = $this->provider->fetch($providerGenerationId);
            } catch (GenerationNotFound) {
                // The provider has no such generation. Nothing to wait for and
                // nothing to charge, so it is closed rather than polled for
                // ever. Rare, and worth knowing about: it means this
                // application recorded an id the provider does not recognise.
                Log::warning('Reconciler found a generation the provider does not recognise.', [
                    'generation_id' => $generation->id,
                    'provider_generation_id' => $providerGenerationId,
                ]);

                $this->close($generation, 'not_found_at_provider', 'The provider has no record of this generation.');

                continue;
            } catch (ProviderException $e) {
                // Transient. Left for the next run rather than treated as an
                // outcome: a provider that cannot be reached says nothing about
                // whether the generation succeeded.
                Log::info('Reconciler could not reach the provider.', [
                    'generation_id' => $generation->id,
                    'reason' => $e->getMessage(),
                ]);

                continue;
            }

            $this->apply->execute($generation, $current);

            $polled++;
        }

        return $polled;
    }

    /**
     * Generations with no provider id, long after they were created.
     *
     * Every submission attempt failed or timed out. A timeout is an unknown
     * outcome rather than a failed one — the request may have been accepted —
     * so these are resubmitted rather than closed.
     *
     * Resubmitting is safe precisely because of the idempotency key written to
     * the row before the first attempt. If the provider did accept an earlier
     * attempt, it returns that same generation and creates nothing; if it never
     * did, this is simply a late first submission. Without the key this would
     * be the most expensive operation in the system: a second paid generation
     * for every ambiguous timeout.
     *
     * Safe is not the same as free. Nothing about the row changes when a
     * resubmission is queued — it has no provider id to record and its
     * created_at does not move — so without a cooldown this query would return
     * the same generation on every run and queue it again, roughly thirty
     * times between the poll threshold and the point it is abandoned. Each of
     * those is a real request counting against a 60-per-minute allowance that
     * other work needs, to no benefit.
     *
     * So a short-lived cache key marks a generation as recently retried. It is
     * a cooldown rather than a lock: losing it to a cache restart costs one
     * extra attempt, which the idempotency key already makes harmless.
     */
    private function resubmitUnacceptedGenerations(): int
    {
        $stuck = Generation::query()
            ->where('status', GenerationStatus::Creating)
            ->whereNull('provider_generation_id')
            ->where('created_at', '<=', $this->pollThreshold())
            ->where('created_at', '>', $this->abandonThreshold())
            ->orderBy('created_at')
            ->limit(self::GENERATION_BATCH)
            ->get();

        $resubmitted = 0;

        foreach ($stuck as $generation) {
            $cooldown = Cache::add(
                "pixelforge:resubmitted:{$generation->id}",
                true,
                (int) config('pixelforge.reconciliation.poll_after_seconds'),
            );

            if (! $cooldown) {
                continue;
            }

            SubmitGenerationToProvider::dispatch($generation->id);

            $resubmitted++;
        }

        return $resubmitted;
    }

    /**
     * Generations old enough that no outcome is coming.
     *
     * Closed as failed and charged nothing. Leaving them open would be the
     * worse choice twice over: the user watches a status that will never
     * change, and the polling queries above keep returning rows that can never
     * resolve, so the work grows without bound.
     *
     * Nothing is charged because nothing is known to have been produced. If the
     * provider did bill for one of these, the discrepancy shows up in the
     * reconciliation report against GET /v1/usage rather than being silently
     * absorbed.
     */
    private function abandonStaleGenerations(): int
    {
        $abandoned = Generation::query()
            ->whereIn('status', $this->nonTerminalValues())
            ->where('created_at', '<=', $this->abandonThreshold())
            ->orderBy('created_at')
            ->limit(self::GENERATION_BATCH)
            ->get();

        foreach ($abandoned as $generation) {
            $this->close(
                $generation,
                'abandoned',
                'This generation did not complete and has been closed. You were not charged.',
            );
        }

        return $abandoned->count();
    }

    /**
     * Closes a generation through the same guarded path everything else uses,
     * so a late webhook cannot be overwritten by this and this cannot overwrite
     * a result that has already arrived.
     */
    private function close(Generation $generation, string $code, string $message): void
    {
        $generation->newQuery()
            ->whereKey($generation->getKey())
            ->where('status_rank', '<', GenerationStatus::Failed->rank())
            ->update([
                'status' => GenerationStatus::Failed->value,
                'status_rank' => GenerationStatus::Failed->rank(),
                'failure_code' => $code,
                'failure_message' => $message,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Matches a stored event to a generation.
     *
     * Our own identifier first, because it exists on the row before the
     * submission is sent and therefore identifies generations the provider's id
     * cannot yet.
     */
    private function findGeneration(WebhookEvent $event): ?Generation
    {
        $reference = $event->clientReference();

        if ($reference !== null) {
            $generation = Generation::find($reference);

            if ($generation !== null) {
                return $generation;
            }
        }

        if ($event->provider_generation_id === null) {
            return null;
        }

        return Generation::query()
            ->where('provider_generation_id', $event->provider_generation_id)
            ->first();
    }

    /**
     * How long a generation is left alone before it is chased.
     *
     * Set beyond the provider's own timings — a few seconds queued, then 5 to
     * 30 seconds processing, or 60 to 90 for a [slow] one — so normal work is
     * never polled while it is simply still running.
     */
    private function pollThreshold(): CarbonImmutable
    {
        return now()->subSeconds((int) config('pixelforge.reconciliation.poll_after_seconds'))->toImmutable();
    }

    private function abandonThreshold(): CarbonImmutable
    {
        return now()->subSeconds((int) config('pixelforge.reconciliation.abandon_after_seconds'))->toImmutable();
    }

    /**
     * @return array<int, string>
     */
    private function nonTerminalValues(): array
    {
        return array_map(
            static fn (GenerationStatus $status): string => $status->value,
            GenerationStatus::nonTerminal(),
        );
    }
}
