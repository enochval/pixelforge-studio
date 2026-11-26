<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Checks that every cached balance equals the sum of that user's ledger.
 *
 * The ledger is authoritative and `users.balance_nanos` is a cache of its sum,
 * maintained in the same transaction as the entry that changes it. That design
 * is only worth anything if the claim is testable, so this is the command that
 * tests it — and it is the check in the definition of done rather than a
 * convenience.
 *
 * The comparison is done in SQL. Summing a ledger in PHP would read every row
 * into memory to answer a question the database can answer by itself, and would
 * be the first thing to fall over once there are enough rows for the answer to
 * matter.
 *
 * Exits non-zero on any disagreement so it can be used as a deployment gate.
 */
final class VerifyBalancesCommand extends Command
{
    protected $signature = 'pixelforge:verify-balances';

    protected $description = 'Assert that every cached balance matches the sum of its ledger entries';

    public function handle(): int
    {
        /** @var array<int, object{id: int, email: string, cached: int, from_ledger: int}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT
                u.id,
                u.email,
                u.balance_nanos AS cached,
                COALESCE(SUM(l.amount_nanos), 0) AS from_ledger
            FROM users u
            LEFT JOIN ledger_entries l ON l.user_id = u.id
            GROUP BY u.id, u.email, u.balance_nanos
            HAVING u.balance_nanos <> COALESCE(SUM(l.amount_nanos), 0)
            ORDER BY u.id
        SQL);

        if ($rows === []) {
            $this->info('Every cached balance matches its ledger.');

            return self::SUCCESS;
        }

        $this->error(count($rows).' balance(s) disagree with the ledger:');

        $this->table(
            ['User', 'Email', 'Cached', 'Ledger', 'Difference'],
            array_map(static fn (object $row): array => [
                $row->id,
                $row->email,
                Money::fromNanos((int) $row->cached)->toUsdString(),
                Money::fromNanos((int) $row->from_ledger)->toUsdString(),
                Money::fromNanos((int) $row->cached - (int) $row->from_ledger)->toUsdString(),
            ], $rows),
        );

        return self::FAILURE;
    }
}
