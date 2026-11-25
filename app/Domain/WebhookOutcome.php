<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What processing decided about a stored webhook delivery.
 *
 * Recorded so that a generation's history can be read back later: whether an
 * event moved it forward, was superseded by one that had already arrived, or
 * referred to a generation that could not yet be identified.
 */
enum WebhookOutcome: string
{
    /** The event moved the generation forward. */
    case Applied = 'applied';

    /** A later event had already been applied, so this one was ignored. */
    case IgnoredStale = 'ignored_stale';

    /** No generation matched yet. The reconciler will try again. */
    case Orphaned = 'orphaned';
}
