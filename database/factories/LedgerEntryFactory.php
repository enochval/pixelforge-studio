<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\LedgerEntryKind;
use App\Domain\Money;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LedgerEntry>
 */
final class LedgerEntryFactory extends Factory
{
    protected $model = LedgerEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'generation_id' => Generation::factory(),
            'kind' => LedgerEntryKind::Charge,
            'amount_nanos' => Money::fromUsd('-0.068200000'),
        ];
    }

    public function topup(string $usd = '10.00'): static
    {
        return $this->state(fn () => [
            'generation_id' => null,
            'kind' => LedgerEntryKind::Topup,
            'amount_nanos' => Money::fromUsd($usd),
        ]);
    }

    public function hold(string $usd = '0.05'): static
    {
        return $this->state(fn () => [
            'kind' => LedgerEntryKind::Hold,
            'amount_nanos' => Money::fromUsd('-'.$usd),
        ]);
    }

    public function holdRelease(string $usd = '0.05'): static
    {
        return $this->state(fn () => [
            'kind' => LedgerEntryKind::HoldRelease,
            'amount_nanos' => Money::fromUsd($usd),
        ]);
    }
}
