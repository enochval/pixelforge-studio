<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Published rates
    |---------------------------------------------------------------------------
    |
    | Decimal strings in USD, never floats: these are converted to integer
    | micro-dollars through bcmath at the point of use. See App\Domain\Money.
    |
    | PROVISIONAL. These values are placeholders pending the published rate
    | table in the provider documentation. The pricing logic reads them as
    | configuration, so correcting them is a change to this file alone and
    | requires no change to App\Domain\Pricing.
    |
    | The rate table in force is recorded here rather than fetched at runtime,
    | so a historical generation can always be explained by the rates that
    | applied when it ran.
    |
    */

    'rates' => [
        'input_per_token' => env('PIXELFORGE_RATE_INPUT_PER_TOKEN', '0.000005'),
        'output_per_token' => env('PIXELFORGE_RATE_OUTPUT_PER_TOKEN', '0.000040'),
        'per_reference_image' => env('PIXELFORGE_RATE_PER_REFERENCE_IMAGE', '0.002000'),
    ],

    /*
    |---------------------------------------------------------------------------
    | Markup
    |---------------------------------------------------------------------------
    |
    | Applied to the provider's cost to produce the amount a user is charged,
    | in basis points. 2500 bps is 25%.
    |
    */

    'markup_basis_points' => (int) env('PIXELFORGE_MARKUP_BASIS_POINTS', 2500),

    /*
    |---------------------------------------------------------------------------
    | Estimation
    |---------------------------------------------------------------------------
    |
    | Output token counts are only known once a generation finishes, so the
    | figure shown before submission rests on these expectations. Input tokens
    | are approximated from the prompt length.
    |
    */

    'estimation' => [
        'characters_per_token' => (int) env('PIXELFORGE_CHARACTERS_PER_TOKEN', 4),

        'output_tokens' => [
            '512x512' => ['standard' => 272, 'hd' => 1_056],
            '1024x1024' => ['standard' => 1_056, 'hd' => 4_160],
            '1024x1792' => ['standard' => 1_840, 'hd' => 6_240],
            '1792x1024' => ['standard' => 1_840, 'hd' => 6_240],
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Provider API
    |---------------------------------------------------------------------------
    */

    'api' => [
        // No default. A fabricated URL would look authoritative and send
        // requests somewhere unintended; an unset value fails immediately and
        // says what is missing.
        'base_url' => env('PIXELFORGE_BASE_URL'),
        'key' => env('PIXELFORGE_API_KEY'),
        'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

        // Rejects webhook deliveries signed outside this window, which bounds
        // how long a captured request stays replayable.
        'webhook_tolerance_seconds' => (int) env('PIXELFORGE_WEBHOOK_TOLERANCE', 300),

        // Caps simultaneous in-flight requests. A concurrency limit is not a
        // rate limit and is not addressed by backoff alone.
        'max_concurrent_requests' => (int) env('PIXELFORGE_MAX_CONCURRENT', 5),
    ],

    /*
    |---------------------------------------------------------------------------
    | Reconciliation
    |---------------------------------------------------------------------------
    |
    | Webhooks can be lost. Generations that have not reached an outcome are
    | polled after the first threshold, and abandoned after the second.
    |
    */

    'reconciliation' => [
        'poll_after_seconds' => (int) env('PIXELFORGE_POLL_AFTER', 60),
        'abandon_after_seconds' => (int) env('PIXELFORGE_ABANDON_AFTER', 1_800),
    ],

];
