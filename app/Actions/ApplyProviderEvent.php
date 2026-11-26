<?php

declare(strict_types=1);

namespace App\Actions;

use App\Domain\GenerationStatus;
use App\Domain\LedgerEntryKind;
use App\Domain\Money;
use App\Domain\Pricing;
use App\Domain\TokenUsage;
use App\Integrations\Pixelforge\Data\ProviderGeneration;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Applies the provider's view of a generation to this application's record of
 * it, and charges for it if it succeeded.
 *
 * One implementation, two callers: the job that handles a webhook, and the
 * reconciler that polls when no webhook arrived. They are given the same type,
 * because the provider sends the full generation object inside every event —
 * so a delivery and a poll carry identical information and there is no reason
 * for two code paths. Two paths would eventually disagree about something, and
 * the disagreement would be a charge.
 *
 * Everything happens in one transaction. A status that moved forward without
 * the charge that belongs with it, or a charge without the balance it adjusts,
 * is a worse state than either change not happening at all.
 *
 * ## What stops a generation being charged twice
 *
 * Two independent mechanisms, both enforced by the database rather than by
 * this code deciding carefully.
 *
 * **The transition is a compare-and-set.** The status change is a single
 * conditional UPDATE: it applies only where the stored status_rank is lower
 * than the rank of the event being applied. The number of rows it affects is
 * the decision — one means this call moved the generation forward, zero means
 * another already had. That is what status_rank is on the row for.
 *
 * The alternative is to read the status, decide in PHP, then write. Those are
 * three operations with gaps between them, and two workers can both read the
 * same status and both decide to apply. Closing that gap needs a row lock held
 * across all three, which works but makes correctness depend on a lock a later
 * refactor could quietly drop. A conditional UPDATE has no gap to close.
 *
 * Terminal states need no separate check, which is the other reason the ranks
 * are chosen as they are: succeeded and failed share the highest rank, so no
 * event can ever satisfy `status_rank < rank` against either of them.
 *
 * **A unique index on (generation_id, kind)** refuses a second charge row even
 * if the guard above were somehow bypassed — by a bug here, a manual
 * redelivery, or the reconciler racing a webhook. The guard is code and could
 * be rewritten; the index is schema and survives being rewritten.
 */
final readonly class ApplyProviderEvent
{
    public function __construct(private Pricing $pricing) {}

    /**
     * @return bool Whether the generation was moved forward. False means the
     *              event was superseded by one already applied.
     */
    public function execute(Generation $generation, ProviderGeneration $event): bool
    {
        return DB::transaction(function () use ($generation, $event): bool {
            if (! $this->claimTransition($generation, $event)) {
                return false;
            }

            $attributes = $event->succeeded()
                ? $this->successAttributes($generation, $event)
                : $this->progressAttributes($event);

            $generation->fill($attributes)->save();

            if ($event->succeeded()) {
                /** @var Money $charge */
                $charge = $attributes['charged_nanos'];

                $this->charge($generation, $charge);
            }

            return true;
        });
    }

    /**
     * Claims the right to apply this event, atomically.
     *
     * A single conditional UPDATE against status_rank. Whether it affected a
     * row is the answer: one means this caller now owns the transition, zero
     * means another already applied this stage or a later one.
     *
     * Only status_rank is touched here, deliberately. It is a plain integer
     * with no cast, so the claim stays a single statement the database
     * evaluates — and the rest of the row is then written through Eloquent,
     * inside the same transaction, with every cast intact. A query-builder
     * update of the whole row would bypass those casts and write a Money object
     * straight at a bigint column.
     */
    private function claimTransition(Generation $generation, ProviderGeneration $event): bool
    {
        $claimed = $generation->newQuery()
            ->whereKey($generation->getKey())
            ->where('status_rank', '<', $event->status->rank())
            ->update(['status_rank' => $event->status->rank()]);

        return $claimed === 1;
    }

    /**
     * What a succeeded generation's row becomes: priced, with the image and the
     * usage behind the price.
     *
     * Priced on the rates the provider fixed when it created this generation,
     * not on whatever is published now. The event carries them, and the row
     * recorded them at submission; the event is preferred because it is the
     * more recent statement of the same fact.
     *
     * @return array<string, mixed>
     */
    private function successAttributes(Generation $generation, ProviderGeneration $event): array
    {
        $usage = $event->usage ?? TokenUsage::none();

        $pricing = $this->pricing->withRates(
            $event->rates !== [] ? $event->rates : ($generation->rates ?? []),
        );

        $providerCost = $pricing->providerCost($usage);

        return [
            'status' => GenerationStatus::Succeeded,
            'usage' => $usage->toArray(),
            'provider_cost_nanos' => $providerCost,
            'charged_nanos' => $pricing->charge($providerCost),
            'image_url' => $event->imageUrl,
            'image_url_expires_at' => $event->imageUrlExpiresAt,
            'completed_at' => $event->completedAt ?? now(),
        ];
    }

    /**
     * What a generation's row becomes when it moved on without finishing, or
     * finished without producing anything.
     *
     * No cost is written in either case. The provider bills only successful
     * generations, and a schema constraint refuses a cost on a row that is not
     * succeeded.
     *
     * @return array<string, mixed>
     */
    private function progressAttributes(ProviderGeneration $event): array
    {
        if ($event->status !== GenerationStatus::Failed) {
            return ['status' => $event->status];
        }

        return [
            'status' => GenerationStatus::Failed,
            'failure_code' => $event->failureCode ?? 'generation_failed',
            'failure_message' => $event->failureMessage ?? 'The generation failed.',
            'completed_at' => $event->completedAt ?? now(),
        ];
    }

    /**
     * Writes the charge and moves the user's cached balance with it.
     *
     * The ledger is authoritative and the cached balance is derived, so both
     * are written together inside the surrounding transaction. A cache updated
     * separately would drift, and a balance that drifts is indistinguishable
     * from a charging bug when someone comes to ask about it.
     *
     * A refused insert means a charge for this generation already exists. That
     * is the index doing its job, and it is not an error: the correct outcome
     * has already been reached, so the balance is left alone rather than being
     * moved a second time for the same work.
     */
    private function charge(Generation $generation, Money $amount): void
    {
        if ($amount->isZero()) {
            // A succeeded generation reporting no usage. Nothing is owed, and
            // a zero-amount ledger entry would be a row that says nothing
            // while occupying the one charge slot this generation has.
            return;
        }

        try {
            // A savepoint, not a second transaction. Postgres aborts an entire
            // transaction when any statement in it fails, so if the unique
            // index below refuses this insert, catching the exception would not
            // be enough on its own: the enclosing transaction would already be
            // dead and the status change above would be lost at commit. Rolling
            // back to a savepoint discards only the refused insert.
            DB::transaction(fn () => LedgerEntry::create([
                'user_id' => $generation->user_id,
                'generation_id' => $generation->id,
                'kind' => LedgerEntryKind::Charge,

                // Negative: a charge moves money away from the user. The sign
                // lives on the amount so that a balance is a plain SUM.
                'amount_nanos' => Money::zero()->minus($amount),

                'created_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return;
        }

        User::query()
            ->whereKey($generation->user_id)
            ->decrement('balance_nanos', $amount->nanos);
    }
}
