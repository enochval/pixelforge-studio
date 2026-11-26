<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * The line between the two applications.
 *
 * An operator reconciles spend and never generates images. A customer
 * generates images and never sees another account's figures. Neither is a
 * subset of the other, so both directions are stated here rather than one
 * being inferred from the absence of the other — a reader should not have to
 * work out what a customer is by elimination.
 */
final readonly class OperatorPolicy
{
    /**
     * The operator console: every user's spend, and the margin taken on it.
     */
    public function viewReport(User $user): bool
    {
        return $user->isOperator();
    }

    /**
     * Generating, and everything that spends credit.
     *
     * Refused to operators on purpose. An account that can read every user's
     * margin should not also be buying credit and generating images on the
     * same balance — the two roles answer to different people, and keeping
     * them apart means a figure in the console is never partly the operator's
     * own activity.
     */
    public function generate(User $user): bool
    {
        return ! $user->isOperator();
    }
}
