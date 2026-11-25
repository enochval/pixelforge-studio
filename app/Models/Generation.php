<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\GenerationStatus;
use App\Domain\Money;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property string $idempotency_key
 * @property string|null $provider_generation_id
 * @property string $prompt
 * @property string $size
 * @property string $quality
 * @property array<int, string> $reference_image_urls
 * @property GenerationStatus $status
 * @property int $status_rank
 * @property Money $estimated_micros
 * @property array<string, int>|null $estimated_usage
 * @property Money|null $provider_cost_micros
 * @property Money|null $charged_micros
 * @property array<string, int>|null $usage
 * @property string|null $image_url
 * @property string|null $failure_reason
 * @property Carbon|null $submitted_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory;

    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'idempotency_key',
        'provider_generation_id',
        'prompt',
        'size',
        'quality',
        'reference_image_urls',
        'status',
        'status_rank',
        'estimated_micros',
        'estimated_usage',
        'provider_cost_micros',
        'charged_micros',
        'usage',
        'image_url',
        'failure_reason',
        'submitted_at',
        'completed_at',
    ];

    /**
     * Keeps the denormalised rank in step with the status it describes.
     *
     * The rank exists so the transition guard can be expressed inside the
     * update statement, and the two values must never disagree. Setting it
     * here rather than at each call site means no path can write one without
     * the other.
     */
    protected static function booted(): void
    {
        self::saving(function (self $generation): void {
            $generation->status_rank = $generation->status->rank();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_image_urls' => 'array',
            'estimated_usage' => 'array',
            'usage' => 'array',
            'estimated_micros' => MoneyCast::class,
            'provider_cost_micros' => MoneyCast::class,
            'charged_micros' => MoneyCast::class,
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
