<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\WebhookOutcome;
use Database\Factories\WebhookEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A received webhook delivery.
 *
 * Rows are written on arrival, before anything acts on them, and the unique
 * constraint on provider_event_id is what makes a repeated delivery a no-op.
 * There is no relation to Generation: an event can arrive before the
 * generation it refers to can be identified, so the association is resolved
 * in application code rather than enforced by a foreign key.
 */
final class WebhookEvent extends Model
{
    /** @use HasFactory<WebhookEventFactory> */
    use HasFactory;

    public $timestamps = false;

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
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }
}
