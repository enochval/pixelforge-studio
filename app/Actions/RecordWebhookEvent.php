<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Writes one delivery into the inbox, if it is not already there.
 *
 * Deduplication happens here, and it happens in the database. The unique
 * constraint on `provider_event_id` is the mechanism: the insert is attempted
 * and a duplicate is the constraint refusing it.
 *
 * Checking first and inserting second would not work. Two deliveries of the
 * same event can arrive at the same moment, on two workers; both would find
 * nothing, and both would insert. A SELECT followed by an INSERT is two
 * operations with a gap between them, and the gap is exactly where the
 * duplicate charge comes from. The constraint has no gap.
 *
 * The key is the **event** id, not the delivery attempt. The provider sends
 * both, and its dashboard can resend any event — which arrives as a new
 * attempt of the same event. Deduplicating on the attempt would let a resend
 * through as new work and charge for it twice.
 */
final readonly class RecordWebhookEvent
{
    /**
     * @param  array<string, mixed>  $payload
     * @return WebhookEvent|null The stored event, or null if it was a duplicate
     */
    public function execute(array $payload): ?WebhookEvent
    {
        $eventId = $payload['id'] ?? null;
        $eventType = $payload['type'] ?? null;

        if (! is_string($eventId) || $eventId === '' || ! is_string($eventType) || $eventType === '') {
            // Verified as genuinely from the provider, yet not shaped like an
            // event. Logged rather than stored, because there is no identifier
            // to deduplicate it by and nothing that could be done with it.
            Log::warning('A verified Pixelforge delivery had no event id or type.', [
                'keys' => array_keys($payload),
            ]);

            return null;
        }

        /** @var array<string, mixed>|null $object */
        $object = $payload['data']['object'] ?? null;

        try {
            // Wrapped in a nested transaction, which Postgres implements as a
            // savepoint. The constraint violation below is an expected outcome
            // rather than an error, but in Postgres a failed statement aborts
            // the whole transaction it ran in — so without a savepoint to roll
            // back to, catching the exception would leave the surrounding
            // transaction unusable and every later statement would fail too.
            $event = DB::transaction(fn (): WebhookEvent => WebhookEvent::create([
                'provider_event_id' => $eventId,
                'event_type' => $eventType,

                // Null for a ping, which is about no generation.
                'provider_generation_id' => is_array($object) && isset($object['id'])
                    ? (string) $object['id']
                    : null,

                'payload' => $payload,
                'received_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            // Already delivered. Nothing to do and nothing wrong: the provider
            // guarantees at-least-once delivery, so this is the normal path for
            // a redelivery or a resend rather than a failure.
            return null;
        }

        // Dispatched after the insert is committed, so the job cannot start
        // before the row it is about is visible to another connection.
        ProcessWebhookEvent::dispatch($event->id)->afterCommit();

        return $event;
    }
}
