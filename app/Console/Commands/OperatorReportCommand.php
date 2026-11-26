<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\BuildOperatorReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Prints the operator report.
 *
 * A command rather than a screen. The audience is whoever runs this system, not
 * its users, and the question it answers — does what we recorded match what the
 * provider billed — is asked when something looks wrong rather than browsed.
 */
final class OperatorReportCommand extends Command
{
    protected $signature = 'pixelforge:report
                            {--days=7 : How many days back to cover, ending today}
                            {--json : Emit the raw structure instead of tables}';

    protected $description = 'Spend, provider cost, profit and reconciliation against the provider';

    public function handle(BuildOperatorReport $build): int
    {
        $days = max(1, (int) $this->option('days'));

        $to = CarbonImmutable::now('UTC');
        $report = $build->execute($to->subDays($days - 1), $to);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->line("<info>Pixelforge Studio</info>  {$report['from']} to {$report['to']}  (UTC)");

        $this->newLine();
        $this->line('<comment>By day</comment>');
        $this->table(
            ['Day', 'Generations', 'Provider cost', 'Charged', 'Profit'],
            array_map(static fn (array $row): array => [
                $row['day'], $row['generations'],
                $row['provider_cost_usd'], $row['charged_usd'], $row['profit_usd'],
            ], $report['by_day']) ?: [['—', 0, '0.000000000', '0.000000000', '0.000000000']],
        );

        $this->line('<comment>By user</comment>');
        $this->table(
            ['User', 'Email', 'Generations', 'Provider cost', 'Charged', 'Profit'],
            array_map(static fn (array $row): array => [
                $row['user_id'], $row['email'], $row['generations'],
                $row['provider_cost_usd'], $row['charged_usd'], $row['profit_usd'],
            ], $report['by_user']) ?: [['—', '—', 0, '0.000000000', '0.000000000', '0.000000000']],
        );

        $totals = $report['totals'];
        $this->line('<comment>Total</comment>');
        $this->line(sprintf(
            '  %d generation(s)   provider %s   charged %s   profit %s',
            $totals['generations'],
            $totals['provider_cost_usd'],
            $totals['charged_usd'],
            $totals['profit_usd'],
        ));

        $this->newLine();
        $this->line('<comment>Reconciliation against GET /v1/usage</comment>');

        $check = $report['reconciliation'];

        if ($check['available'] !== true) {
            // Reported as unavailable rather than as agreement. No answer and
            // an answer of zero mean opposite things.
            $this->warn('  Unavailable: '.$check['reason']);

            return self::SUCCESS;
        }

        $this->line('  recorded here  '.$check['recorded_usd']);
        $this->line('  provider says  '.$check['provider_usd']);
        $this->line('  difference     '.$check['difference_usd']);

        $check['agrees'] === true
            ? $this->info('  Records agree with the provider exactly.')
            : $this->warn('  Records differ. A generation completing either side of midnight UTC '
                .'can explain a small daily difference; a total that does not close cannot.');

        return self::SUCCESS;
    }
}
