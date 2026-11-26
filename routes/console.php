<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
*/

/*
 * The reconciler, every minute.
 *
 * Webhook delivery is explicitly not guaranteed by the provider, so this is
 * what stops a lost event leaving a generation running for ever and a provider
 * cost that was never charged for. A minute is frequent enough that a user
 * watching the screen sees it resolve, and the run costs nothing when there is
 * nothing outstanding — the queries are served by a partial index covering only
 * in-flight rows.
 *
 * withoutOverlapping because a slow provider can make one run outlast the
 * minute it started in. Two concurrent runs would be safe — every write goes
 * through the same guarded path as the webhook handler — but they would poll
 * the same generations twice and spend the provider's rate limit doing it.
 */
Schedule::command('pixelforge:reconcile')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();
