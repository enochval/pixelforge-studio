<?php

declare(strict_types=1);

namespace App\Actions;

use App\Domain\Money;
use App\Integrations\Pixelforge\Contracts\ImageProvider;
use App\Integrations\Pixelforge\Exceptions\ProviderException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What was spent, what it cost, and whether our records agree with the
 * provider's.
 *
 * Three groupings, all of them aggregated by the database rather than in PHP.
 * Reading every generation and every ledger entry into memory to add them up
 * would work on a demonstration dataset and fall over on a real one, and the
 * database is better at this than any loop would be.
 *
 * The reconciliation is the part that earns its place. Everything else here is
 * a sum of this application's own records, which will agree with itself whether
 * or not it is right. GET /v1/usage is the provider's own account of what it
 * billed, so comparing against it is the only check that can actually find a
 * charging error — a generation billed but never recorded, or recorded at the
 * wrong rate.
 *
 * A non-zero difference is not automatically a bug. A generation completing
 * either side of midnight UTC lands on different days in the two records, and
 * the provider bills on the day a generation *completed* while this application
 * groups by the same field for exactly that reason. The figure to watch is the
 * total over a range, not any single day.
 */
final readonly class BuildOperatorReport
{
    public function __construct(private ImageProvider $provider) {}

    /**
     * @return array{
     *     from: string,
     *     to: string,
     *     by_day: array<int, array<string, mixed>>,
     *     by_user: array<int, array<string, mixed>>,
     *     totals: array<string, mixed>,
     *     reconciliation: array<string, mixed>,
     * }
     */
    public function execute(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'by_day' => $this->byDay($from, $to),
            'by_user' => $this->byUser($from, $to),
            'totals' => $this->totals($from, $to),
            'reconciliation' => $this->reconciliation($from, $to),
        ];
    }

    /**
     * Succeeded generations by the UTC day they completed.
     *
     * Grouped on completed_at rather than created_at so the rows line up with
     * the provider's own billing day. Grouping on creation would put a
     * generation submitted at 23:59 on the wrong side of the comparison and
     * produce a difference that looks like a charging error.
     *
     * @return array<int, array<string, mixed>>
     */
    private function byDay(CarbonImmutable $from, CarbonImmutable $to): array
    {
        /** @var array<int, object{day: string, generations: int, provider_cost: int, charged: int}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT
                (completed_at AT TIME ZONE 'UTC')::date::text AS day,
                COUNT(*)                                      AS generations,
                COALESCE(SUM(provider_cost_nanos), 0)         AS provider_cost,
                COALESCE(SUM(charged_nanos), 0)               AS charged
            FROM generations
            WHERE status = 'succeeded'
              AND completed_at >= ?
              AND completed_at < ?
            GROUP BY 1
            ORDER BY 1
        SQL, [$from->startOfDay(), $to->addDay()->startOfDay()]);

        return array_map(fn (object $row): array => [
            'day' => $row->day,
            'generations' => (int) $row->generations,
            ...$this->money((int) $row->provider_cost, (int) $row->charged),
        ], $rows);
    }

    /**
     * The same figures per user, so one account's spend can be explained
     * without reading its generations one by one.
     *
     * @return array<int, array<string, mixed>>
     */
    private function byUser(CarbonImmutable $from, CarbonImmutable $to): array
    {
        /** @var array<int, object{user_id: int, email: string, generations: int, provider_cost: int, charged: int}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT
                u.id                                    AS user_id,
                u.email,
                COUNT(g.id)                             AS generations,
                COALESCE(SUM(g.provider_cost_nanos), 0) AS provider_cost,
                COALESCE(SUM(g.charged_nanos), 0)       AS charged
            FROM users u
            JOIN generations g ON g.user_id = u.id
            WHERE g.status = 'succeeded'
              AND g.completed_at >= ?
              AND g.completed_at < ?
            GROUP BY u.id, u.email
            ORDER BY SUM(g.charged_nanos) DESC
        SQL, [$from->startOfDay(), $to->addDay()->startOfDay()]);

        return array_map(fn (object $row): array => [
            'user_id' => (int) $row->user_id,
            'email' => $row->email,
            'generations' => (int) $row->generations,
            ...$this->money((int) $row->provider_cost, (int) $row->charged),
        ], $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function totals(CarbonImmutable $from, CarbonImmutable $to): array
    {
        /** @var object{generations: int, provider_cost: int, charged: int} $row */
        $row = DB::selectOne(<<<'SQL'
            SELECT
                COUNT(*)                              AS generations,
                COALESCE(SUM(provider_cost_nanos), 0) AS provider_cost,
                COALESCE(SUM(charged_nanos), 0)       AS charged
            FROM generations
            WHERE status = 'succeeded'
              AND completed_at >= ?
              AND completed_at < ?
        SQL, [$from->startOfDay(), $to->addDay()->startOfDay()]);

        return [
            'generations' => (int) $row->generations,
            ...$this->money((int) $row->provider_cost, (int) $row->charged),
        ];
    }

    /**
     * Our record of what the provider billed, against the provider's own.
     *
     * The only figure here that can disprove anything. If the provider is
     * unreachable the comparison is reported as unavailable rather than as
     * zero: a difference of zero and no answer at all mean opposite things, and
     * conflating them would turn a broken check into a passing one.
     *
     * @return array<string, mixed>
     */
    private function reconciliation(CarbonImmutable $from, CarbonImmutable $to): array
    {
        try {
            $usage = $this->provider->usage($from, $to);
        } catch (ProviderException $e) {
            return [
                'available' => false,
                'reason' => $e->getMessage(),
            ];
        }

        /** @var array<int, array<string, mixed>> $days */
        $days = $usage['data'] ?? [];

        $provider = Money::zero();

        foreach ($days as $day) {
            $provider = $provider->plus(Money::fromUsd((string) ($day['cost_usd'] ?? '0')));
        }

        /** @var object{provider_cost: int} $row */
        $row = DB::selectOne(<<<'SQL'
            SELECT COALESCE(SUM(provider_cost_nanos), 0) AS provider_cost
            FROM generations
            WHERE status = 'succeeded'
              AND completed_at >= ?
              AND completed_at < ?
        SQL, [$from->startOfDay(), $to->addDay()->startOfDay()]);

        $recorded = Money::fromNanos((int) $row->provider_cost);
        $difference = $recorded->minus($provider);

        return [
            'available' => true,
            'recorded_nanos' => $recorded->nanos,
            'recorded_usd' => $recorded->toUsdString(),
            'provider_nanos' => $provider->nanos,
            'provider_usd' => $provider->toUsdString(),
            'difference_nanos' => $difference->nanos,
            'difference_usd' => $difference->toUsdString(),
            'agrees' => $difference->isZero(),
        ];
    }

    /**
     * Provider cost, what was charged, and the margin between them.
     *
     * Profit is a subtraction rather than a stored column, so it cannot
     * disagree with the two figures it comes from.
     *
     * @return array<string, mixed>
     */
    private function money(int $providerCostNanos, int $chargedNanos): array
    {
        $cost = Money::fromNanos($providerCostNanos);
        $charged = Money::fromNanos($chargedNanos);
        $profit = $charged->minus($cost);

        return [
            'provider_cost_nanos' => $cost->nanos,
            'provider_cost_usd' => $cost->toUsdString(),
            'charged_nanos' => $charged->nanos,
            'charged_usd' => $charged->toUsdString(),
            'profit_nanos' => $profit->nanos,
            'profit_usd' => $profit->toUsdString(),
        ];
    }
}
