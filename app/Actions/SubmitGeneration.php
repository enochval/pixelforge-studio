<?php

declare(strict_types=1);

namespace App\Actions;

use App\Domain\GenerationRequest;
use App\Domain\GenerationStatus;
use App\Domain\Pricing;
use App\Exceptions\InsufficientCredit;
use App\Jobs\SubmitGenerationToProvider;
use App\Models\Generation;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Support\Str;

/**
 * Accepts a generation from a user and puts it on its way.
 *
 * The row is written and committed before the provider is contacted. That
 * ordering is the point of this class: the provider can dispatch its first
 * webhook before the response to the submission has been persisted, and an
 * event that arrives for a generation with no row has nowhere to land.
 *
 * Writing first also means a submission that never reaches the provider is
 * still visible — to the user, and to the reconciler that will finish it.
 */
final readonly class SubmitGeneration
{
    public function __construct(private Pricing $pricing, private Ledger $ledger) {}

    /**
     * @throws InsufficientCredit When the balance cannot cover the quote
     */
    public function execute(User $user, GenerationRequest $request): Generation
    {
        $quote = $this->pricing->quote($request);

        $generation = Generation::create([
            'user_id' => $user->id,
            'idempotency_key' => Str::uuid()->toString(),
            'prompt' => $request->prompt,
            'size' => $request->size,
            'quality' => $request->quality,
            'reference_image_urls' => $request->referenceImageUrls,
            'status' => GenerationStatus::Creating,

            // The ceiling the user was quoted, recorded alongside what they are
            // eventually charged so the two can be compared afterwards. See
            // Pricing for why a quote is a ceiling and not an expectation.
            'quoted_nanos' => $quote,
            'quoted_usage' => $this->pricing->maximumUsage($request)->toArray(),
        ]);

        // The quote is reserved, not spent. A hold stops a user committing
        // money they do not have, and stops two simultaneous submissions both
        // spending the same balance — the check and the deduction are one
        // statement inside Ledger::hold().
        //
        // Placed after the row exists because a hold belongs to a generation:
        // the schema requires an entry other than a top-up to name one.
        if (! $this->ledger->hold($generation, $quote)) {
            // Deleted rather than left as a failed generation. Nothing was
            // submitted, nothing was charged, and a row recording that a user
            // briefly lacked credit is not history worth keeping.
            $generation->delete();

            throw new InsufficientCredit(
                'This generation costs up to '.$quote->toUsdString(4).' and your balance will not cover it.',
            );
        }

        // afterCommit, because a job dispatched inside an uncommitted
        // transaction can begin before the row it refers to is visible — or
        // after a rollback, refer to a row that never existed at all.
        SubmitGenerationToProvider::dispatch($generation->id)->afterCommit();

        return $generation;
    }
}
