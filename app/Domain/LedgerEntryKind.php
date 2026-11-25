<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * The four movements of money this application records.
 *
 * A generation reserves its estimate with a hold, then settles: a successful
 * one releases the hold and writes a charge for the actual cost, and a failed
 * one releases the hold alone. The pair therefore nets to zero when nothing
 * was produced, which is what makes a failed generation free rather than
 * refunded.
 */
enum LedgerEntryKind: string
{
    case Topup = 'topup';
    case Hold = 'hold';
    case HoldRelease = 'hold_release';
    case Charge = 'charge';

    /** Whether this kind moves money away from the user. */
    public function isDebit(): bool
    {
        return $this === self::Hold || $this === self::Charge;
    }

    public function requiresGeneration(): bool
    {
        return $this !== self::Topup;
    }
}
