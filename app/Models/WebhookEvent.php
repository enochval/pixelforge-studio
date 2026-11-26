<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\WebhookOutcome;
use Carbon\CarbonImmutable;
use Database\Factories\WebhookEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One delivery from the provider, stored before anything is done about it.
 *
 * This is the transactional inbox. The endpoint verifies a delivery and writes
 * it here, and all the work happens afterwards in a queued job reading from
 * this table. Two things follow from that order.
 *
 * The provider counts any 2xx within ten seconds as delivered and redelivers
 * anything else, so the request has to end quickly. Charging inline would be
 * fast enough most of the time and time out under load — at which point the
 * provider would redeliver an event that had already been charged for.
 *
 * And a delivery is never lost to a crash. Once the row is committed the event
 * exists independently of the worker that was going to process it, so a worker
 * dying mid-job costs a retry rather than a generation.
 *
 * @property int $id
 * @property string $provider_event_id
 * @property string $event_type
 * @property string|null $provider_generation_id
 * @property array<string, mixed> $payload
 * @property WebhookOutcome|null $outcome
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 */
final class WebhookEvent extends Model
{
    /** @use HasFactory<WebhookEventFactory> */
    use HasFactory;

    /**
     * Append-only in practice: only `outcome` and `processed_at` are ever
     * written after insert, both once, by the job that handles the event.
     */
    public const UPDATED_AT = null;

    public const CREATED_AT = null;

    protected $fillable = [
        'provider_event_id',
        'event_type',
        'provider_generation_id',
        'payload',
        'outcome',
        'received_at',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'outcome' => WebhookOutcome::class,
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /**
     * The generation object the provider sends inside every event.
     *
     * Events about a generation carry its full state at the moment the event
     * happened, which is why a webhook and a polled response can be handled by
     * the same code. A `ping` carries no object, hence the null.
     *
     * @return array<string, mixed>|null
     */
    public function generationPayload(): ?array
    {
        $object = $this->payload['data']['object'] ?? null;

        return is_array($object) ? $object : null;
    }

    /**
     * Our own identifier, sent as metadata on submission and returned on every
     * event about that generation.
     *
     * Preferred over the provider's identifier when matching, because it is
     * written to the row before the submission is sent and is therefore known
     * even for an event that arrives before the create response does.
     */
    public function clientReference(): ?string
    {
        $reference = $this->generationPayload()['metadata']['generation_id'] ?? null;

        return is_string($reference) ? $reference : null;
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }
}
