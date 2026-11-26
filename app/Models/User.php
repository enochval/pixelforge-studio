<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Domain\Money;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * The declared $attributes default below makes PHPStan read balance_nanos as
 * the raw int it is initialised with, so the cast's type is stated here.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Money $balance_nanos
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * A new user has no credit, and the column defaults to zero.
     *
     * Declared here as well so a model that has not been round-tripped through
     * the database still presents a balance. Without it the attribute is simply
     * absent on a freshly created instance, the cast is handed null, and a
     * balance reads as nothing rather than as zero.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'balance_nanos' => 0,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'balance_nanos' => MoneyCast::class,
        ];
    }

    /**
     * @return HasMany<Generation, $this>
     */
    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class);
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * The authoritative balance, summed from the ledger.
     *
     * `balance_nanos` is a cache of this figure maintained alongside each
     * entry. Reading the ledger directly is how the two are checked against
     * one another.
     */
    public function ledgerBalance(): Money
    {
        return Money::fromNanos((int) $this->ledgerEntries()->sum('amount_nanos'));
    }
}
