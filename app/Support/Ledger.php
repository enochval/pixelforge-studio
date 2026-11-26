<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\LedgerEntryKind;
use App\Domain\Money;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Support\Exceptions\InsufficientFunds;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only place money moves.
 *
 * Every operation writes a ledger entry and adjusts the cached balance in the
 * same statement pair, inside a transaction. That pairing is the whole point:
 * the ledger is authoritative and `users.balance_nanos` is a cache of its sum,
 * so a path that moved one without the other would leave a balance that cannot
 * be explained — and a balance that cannot be explained is indistinguishable
 * from a charging bug when a user asks about it.
 *
 * Every operation is also idempotent, enforced by the unique index on
 * (generation_id, kind) rather than by checking first. A generation has at most
 * one hold, one release and one charge, so a repeat is refused by the database
 * and the cache is left alone. That matters because all three of the paths that
 * settle a generation — the webhook, the reconciler, and a submission that
 * failed outright — can legitimately try to release the same hold.
 *
 * Amounts are always positive when passed in. The sign belongs to the kind and
 * is applied here, so a balance stays a plain SUM with nothing to get wrong at
 * the point of reading, and no caller can accidentally credit a charge.
 */
final readonly class Ledger
{
    /**
     * Reserves funds against a generation, if the user has them.
     *
     * The affordability check and the deduction are one statement. A read
     * followed by a write would let two concurrent submissions both see the
     * same balance and both decide it was sufficient — the classic overspend,
     * and the reason this is a conditional UPDATE whose affected-row count is
     * the answer rather than a comparison in PHP.
     *
     * @return bool False when the balance could not cover it, in which case
     *              nothing was written
     */
    public function hold(Generation $generation, Money $amount): bool
    {
        try {
            return DB::transaction(function () use ($generation, $amount): bool {
                // The entry is written before the balance moves. If the
                // conditional update below then finds the funds are not there,
                // throwing rolls the entry back with it — whereas moving the
                // balance first and failing to record why would leak money.
                if (! $this->write($generation, LedgerEntryKind::Hold, $amount->negated())) {
                    // A hold already exists for this generation, so the funds
                    // are already reserved. Nothing to do, and affordable.
                    return true;
                }

                $claimed = User::query()
                    ->whereKey($generation->user_id)
                    ->where('balance_nanos', '>=', $amount->nanos)
                    ->decrement('balance_nanos', $amount->nanos);

                if ($claimed === 0) {
                    throw new InsufficientFunds;
                }

                return true;
            });
        } catch (InsufficientFunds) {
            return false;
        }
    }

    /**
     * Returns a hold in full.
     *
     * Always the amount that was held, read back from the hold entry rather
     * than recalculated. Releasing a recomputed figure would leak money in
     * whichever direction the recomputation differed, and the quote it was
     * based on can change between submission and settlement if the published
     * rates move.
     *
     * Silently does nothing when there is no hold to release, or when it has
     * already been released. Both are ordinary: the three paths that settle a
     * generation all call this, and only the first of them has work to do.
     */
    public function release(Generation $generation): void
    {
        DB::transaction(function () use ($generation): void {
            $hold = LedgerEntry::query()
                ->where('generation_id', $generation->id)
                ->where('kind', LedgerEntryKind::Hold)
                ->first();

            if ($hold === null) {
                return;
            }

            // The hold is negative, so the release is its opposite.
            $amount = $hold->amount_nanos->negated();

            if ($this->write($generation, LedgerEntryKind::HoldRelease, $amount)) {
                User::query()
                    ->whereKey($generation->user_id)
                    ->increment('balance_nanos', $amount->nanos);
            }
        });
    }

    /**
     * Charges what the generation actually cost.
     *
     * Called after the hold has been released, so the net movement is the real
     * cost rather than the ceiling: hold, release, charge nets to exactly what
     * was spent, and a failed generation — released but never charged — nets to
     * zero. That is what makes a failure free rather than refunded.
     */
    public function charge(Generation $generation, Money $amount): void
    {
        if ($amount->isZero()) {
            // A succeeded generation reporting no usage. A zero entry would say
            // nothing while occupying the one charge slot this generation has.
            return;
        }

        DB::transaction(function () use ($generation, $amount): void {
            if ($this->write($generation, LedgerEntryKind::Charge, $amount->negated())) {
                User::query()
                    ->whereKey($generation->user_id)
                    ->decrement('balance_nanos', $amount->nanos);
            }
        });
    }

    /**
     * Adds credit, belonging to a user but to no generation.
     *
     * The only way funds enter the system. A balance set directly would be
     * money with no entry behind it, which breaks the one invariant this class
     * exists to hold: that the cache equals the sum of the ledger.
     *
     * Several top-ups per user must remain possible, which is why the unique
     * index on (generation_id, kind) is partial and excludes null generations.
     */
    public function topUp(User $user, Money $amount): LedgerEntry
    {
        return DB::transaction(function () use ($user, $amount): LedgerEntry {
            $entry = LedgerEntry::create([
                'user_id' => $user->id,
                'generation_id' => null,
                'kind' => LedgerEntryKind::Topup,
                'amount_nanos' => $amount,
                'created_at' => now(),
            ]);

            User::query()->whereKey($user->id)->increment('balance_nanos', $amount->nanos);

            return $entry;
        });
    }

    /**
     * Writes one entry, treating a refused duplicate as an ordinary outcome.
     *
     * Wrapped in a nested transaction, which Postgres implements as a
     * savepoint. A failed statement aborts the whole transaction it ran in, so
     * catching the constraint violation is not enough on its own — without a
     * savepoint to roll back to, the enclosing transaction would already be
     * dead and every later statement in it would fail too.
     *
     * @return bool Whether this call wrote the entry, and therefore whether
     *              the caller should move the cached balance
     */
    private function write(Generation $generation, LedgerEntryKind $kind, Money $amount): bool
    {
        try {
            DB::transaction(fn () => LedgerEntry::create([
                'user_id' => $generation->user_id,
                'generation_id' => $generation->id,
                'kind' => $kind,
                'amount_nanos' => $amount,
                'created_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }
}
