<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BuildOperatorReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The operator report: what users were charged, what the provider charged us,
 * and the margin between them.
 *
 * Read-only. Nothing here writes, so there is no action and no form — the page
 * exists to answer whether the figures agree, and the one row that can disprove
 * anything is the comparison against the provider's own usage reporting.
 */
final class OperatorReportController extends Controller
{
    public function index(Request $request, BuildOperatorReport $build): Response
    {
        $days = max(1, min(92, (int) $request->integer('days', 7)));

        $to = CarbonImmutable::now('UTC');

        return Inertia::render('Operator/Index', [
            'days' => $days,
            'report' => $build->execute($to->subDays($days - 1), $to),
        ]);
    }
}
