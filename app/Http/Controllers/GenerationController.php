<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SubmitGeneration;
use App\Domain\Pricing;
use App\Http\Requests\QuoteGenerationRequest;
use App\Http\Requests\StoreGenerationRequest;
use App\Integrations\Pixelforge\RateCardRepository;
use App\Models\Generation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class GenerationController extends Controller
{
    /**
     * The one screen: what a user can ask for, and what they have asked for.
     */
    public function index(Request $request, RateCardRepository $rates): Response
    {
        $card = $rates->current();

        return Inertia::render('Generations/Index', [
            // The sizes and qualities offered are the ones that can be priced,
            // read from the same rate card that validation reads. A form
            // offering an option the application cannot price would produce a
            // validation error the user could not have predicted.
            'sizes' => $card->sizes(),
            'qualities' => $card->qualities(),

            'balance' => $request->user()->balance_nanos->nanos,

            'generations' => $this->history($request),
        ]);
    }

    /**
     * Prices a request without submitting it.
     *
     * Computed by the same Pricing class that performs the charge. A rate table
     * in JavaScript would be a second source of truth for the one value in the
     * system that most needs a single one — and the two would eventually
     * disagree, leaving a user billed something other than what they agreed to.
     */
    public function quote(QuoteGenerationRequest $request, Pricing $pricing): JsonResponse
    {
        $quote = $pricing->quote($request->toGenerationRequest());

        // A ceiling, not a prediction: two of the four token counts are only
        // known once a generation completes, which is why the interface says
        // "up to" rather than naming a figure.
        return response()->json(['nanos' => $quote->nanos]);
    }

    /**
     * Accepts a generation and returns immediately.
     *
     * The provider is never called from a web request: the action records the
     * generation and queues the submission, so the user sees their request on
     * the page whatever the provider is doing.
     */
    public function store(StoreGenerationRequest $request, SubmitGeneration $submit): RedirectResponse
    {
        $submit->execute($request->user(), $request->toGenerationRequest());

        return back();
    }

    /**
     * This user's generations, newest first.
     *
     * Served by the (user_id, created_at) index. Shaped here rather than in the
     * model so that what the interface receives is a deliberate choice: a
     * prompt and a cost, not a serialised row carrying an idempotency key and a
     * provider identifier the browser has no use for.
     *
     * @return array<int, array<string, mixed>>
     */
    private function history(Request $request): array
    {
        return Generation::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (Generation $generation): array => [
                'id' => $generation->id,
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'status' => $generation->status->value,
                'is_terminal' => $generation->status->isTerminal(),

                'quoted_nanos' => $generation->quoted_nanos->nanos,
                'charged_nanos' => $generation->charged_nanos?->nanos,

                // Only offered while the signed URL still works. The provider's
                // URLs stop working 24 hours after a generation completes, and
                // a dead image is worse than none: it looks like the
                // application lost the file.
                'image_url' => $this->liveImageUrl($generation),

                'failure_message' => $generation->failure_message,
                'created_at' => $generation->created_at->toIso8601String(),
            ])
            ->all();
    }

    private function liveImageUrl(Generation $generation): ?string
    {
        if ($generation->image_url === null) {
            return null;
        }

        $expiry = $generation->image_url_expires_at;

        return $expiry === null || $expiry->isFuture() ? $generation->image_url : null;
    }
}
