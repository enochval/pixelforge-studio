<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SubmitGeneration;
use App\Http\Requests\StoreGenerationRequest;
use Illuminate\Http\RedirectResponse;

final class GenerationController extends Controller
{
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
}
