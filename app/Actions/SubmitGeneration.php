<?php

declare(strict_types=1);

namespace App\Actions;

use App\Domain\GenerationRequest;
use App\Domain\GenerationStatus;
use App\Domain\Pricing;
use App\Jobs\SubmitGenerationToProvider;
use App\Models\Generation;
use App\Models\User;
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
    public function __construct(private Pricing $pricing) {}

    public function execute(User $user, GenerationRequest $request): Generation
    {
        $generation = Generation::create([
            'user_id' => $user->id,
            'idempotency_key' => Str::uuid()->toString(),
            'prompt' => $request->prompt,
            'size' => $request->size,
            'quality' => $request->quality,
            'reference_image_urls' => $request->referenceImageUrls,
            'status' => GenerationStatus::Creating,

            // The price the user was shown, recorded alongside what they are
            // eventually charged so the two can be compared afterwards.
            'estimated_micros' => $this->pricing->estimate($request),
            'estimated_usage' => $this->pricing->estimatedUsage($request)->toArray(),
        ]);

        // afterCommit, because a job dispatched inside an uncommitted
        // transaction can begin before the row it refers to is visible — or
        // after a rollback, refer to a row that never existed at all.
        SubmitGenerationToProvider::dispatch($generation->id)->afterCommit();

        return $generation;
    }
}
