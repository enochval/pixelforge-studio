<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Who may see the operator report.
 *
 * The report shows every user's spend and the margin taken on it, which is
 * commercial information about other people — so it is gated rather than left
 * to anyone who guesses the URL.
 *
 * Membership comes from configuration. A roles table for a list with one entry
 * would be three more things to maintain to express the same fact, and because
 * the check lives behind a single Gate, replacing configuration with a column
 * later is a change to one method rather than to every route.
 */
final readonly class OperatorPolicy
{
    public function viewReport(User $user): bool
    {
        /** @var array<int, string> $operators */
        $operators = config('pixelforge.operators');

        return in_array($user->email, $operators, strict: true);
    }
}
