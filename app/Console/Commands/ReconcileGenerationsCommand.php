<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ReconcileGenerations;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Runs the reconciler.
 *
 * Scheduled every minute, and runnable by hand — which matters more than it
 * sounds. When a generation is stuck, the first thing anyone wants is to make
 * the system catch up now and say what it did, rather than wait for a timer and
 * read it out of a log afterwards.
 */
final class ReconcileGenerationsCommand extends Command
{
    protected $signature = 'pixelforge:reconcile';

    protected $description = 'Resolve generations whose provider outcome never arrived';

    public function handle(ReconcileGenerations $reconcile): int
    {
        $result = $reconcile->execute();

        foreach ($result as $action => $count) {
            $this->line(sprintf('%-18s %d', str_replace('_', ' ', $action), $count));
        }

        // Logged only when there was something to do. A scheduled task running
        // every minute would otherwise write 1,440 lines a day saying nothing
        // happened, which is how the lines that do matter get missed.
        if (array_sum($result) > 0) {
            Log::info('Reconciler resolved outstanding generations.', $result);
        }

        return self::SUCCESS;
    }
}
