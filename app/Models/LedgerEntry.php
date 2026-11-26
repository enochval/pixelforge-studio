<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\LedgerEntryKind;
use App\Domain\Money;
use Carbon\CarbonImmutable;
use Database\Factories\LedgerEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement of money, never modified after it is written.
 *
 * There is no UPDATED_AT because nothing updates: a correction is a new
 * compensating entry, which is what allows a balance to be reconstructed at
 * any point in time.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $generation_id
 * @property LedgerEntryKind $kind
 * @property Money $amount_nanos
 * @property CarbonImmutable $created_at
 */
final class LedgerEntry extends Model
{
    /** @use HasFactory<LedgerEntryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'generation_id',
        'kind',
        'amount_nanos',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => LedgerEntryKind::class,
            'amount_nanos' => MoneyCast::class,
            'created_at' => 'datetime',
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
     * @return BelongsTo<Generation, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
