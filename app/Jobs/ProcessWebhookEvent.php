<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ApplyProviderEvent;
use App\Domain\WebhookOutcome;
use App\Integrations\Pixelforge\GenerationTranslator;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Decides what one stored delivery means, and applies it.
 *
 * Separated from the request that received it because the provider allows ten
 * seconds for a response and redelivers anything slower. Charging inline would
 * work until it did not, and the failure mode is the provider redelivering an
 * event that was already charged for.
 *
 * Every event ends with an outcome recorded against it, including the ones
 * that change nothing. "Received, and deliberately not applied" and "never
 * processed" look identical without that, and only one of them is a problem.
 */
final class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $webhookEventId) {}

    /**
     * Serialises processing per generation.
     *
     * Two events for one generation arriving together would otherwise be
     * handled at the same time. The atomic claim inside ApplyProviderEvent
     * already makes that safe, so this is about not doing the work twice rather
     * than about correctness — and a blocked job is released, not discarded,
     * because the two events are different events and both still need
     * handling.
     *
     * Keyed on the generation rather than the event: two deliveries of one
     * event are already stopped by the inbox, and what remains to coordinate
     * is two *different* events about the same generation.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $event = WebhookEvent::find($this->webhookEventId);
        $key = $event === null ? null : $event->provider_generation_id;

        return [new WithoutOverlapping($key ?? "event:{$this->webhookEventId}")];
    }

    public function handle(ApplyProviderEvent $apply, GenerationTranslator $translator): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

        if ($event === null || $event->isProcessed()) {
            return;
        }

        $payload = $event->generationPayload();

        if ($payload === null) {
            // A ping, or anything else carrying no generation object. Valid,
            // and about nothing this application tracks.
            $this->finish($event, WebhookOutcome::Acknowledged);

            return;
        }

        $generation = $this->match($event);

        if ($generation === null) {
            // The documented race: the provider can dispatch an event before
            // the response to the submission has been written. The event is
            // kept and left unprocessed rather than discarded, and the
            // reconciler applies it once the generation can be identified.
            //
            // Answering the provider with an error instead would have it
            // redeliver — which would work, but only by accident, and only
            // within the six attempts it is willing to make.
            $this->finish($event, WebhookOutcome::Orphaned, processed: false);

            return;
        }

        // Translated through the same code that reads a polled response, so a
        // webhook and a poll cannot be understood differently.
        $applied = $apply->execute($generation, $translator->fromPayload($payload));

        $this->finish($event, $applied ? WebhookOutcome::Applied : WebhookOutcome::IgnoredStale);
    }

    /**
     * Finds the generation an event is about.
     *
     * Our own identifier is tried first. It is written to the row before the
     * submission is sent, so it identifies a generation even when the
     * provider's identifier has not been recorded yet — which is precisely the
     * case where matching otherwise fails.
     *
     * The provider's identifier is the fallback, for an event whose metadata is
     * missing or whose generation was submitted before metadata was sent.
     */
    private function match(WebhookEvent $event): ?Generation
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

    private function finish(WebhookEvent $event, WebhookOutcome $outcome, bool $processed = true): void
    {
        $event->update([
            'outcome' => $outcome,

            // An orphan records what was decided but stays unprocessed, so it
            // remains in the partial index the reconciler scans. Marking it
            // processed would lose the event quietly.
            'processed_at' => $processed ? now() : null,
        ]);
    }
}
