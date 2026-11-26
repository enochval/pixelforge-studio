<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * What processing decided about a stored webhook delivery.
 *
 * Recorded so a generation's history can be read back later: whether an event
 * moved it forward, was superseded by one that had already arrived, or referred
 * to a generation that could not yet be identified.
 *
 * A delivery that was received and understood but changed nothing is not a
 * failure, and none of these cases is. An event that could not be verified
 * never reaches this table at all.
 */
enum WebhookOutcome: string
{
    /** The event moved the generation forward. */
    case Applied = 'applied';

    /** A later event had already been applied, so this one was ignored. */
    case IgnoredStale = 'ignored_stale';

    /** No generation matched yet. The reconciler will try again. */
    case Orphaned = 'orphaned';

    /**
     * Valid, and about no generation.
     *
     * The provider's dashboard sends a `ping` to let an endpoint be tested
     * before any real traffic exists. It carries no generation, so there is
     * nothing to apply — but it is still stored, because the first thing anyone
     * does with a new webhook URL is press that button, and a row here is the
     * evidence that the signature and the endpoint both work.
     */
    case Acknowledged = 'acknowledged';
}
