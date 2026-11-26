<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------------
    | Provider API
    |---------------------------------------------------------------------------
    */

    'api' => [
        'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),
        'key' => env('PIXELFORGE_API_KEY'),

        // Each API key has its own webhook URL and its own signing secret, so
        // this belongs to one environment's key and cannot be shared between
        // environments.
        'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

        'timeout_seconds' => (int) env('PIXELFORGE_TIMEOUT', 15),
    ],

    /*
    |---------------------------------------------------------------------------
    | Limits this application enforces
    |---------------------------------------------------------------------------
    |
    | Only the concurrency ceiling is here, because it is the only one of the
    | provider's three account limits that this application has to hold itself
    | to. Waiting does not clear a concurrency limit unless one of our own
    | in-flight generations finishes, so the semaphore stops requests being
    | made that could only be rejected.
    |
    | The per-minute and per-day limits are deliberately not mirrored here. The
    | provider enforces them and says so in a typed error, and a second copy of
    | a number it owns would be one more thing to keep in step for no gain.
    | They are recorded in docs/PROVIDER-NOTES.md, where reference material
    | about the contract belongs.
    |
    */

    'limits' => [
        'max_concurrent_generations' => (int) env('PIXELFORGE_MAX_CONCURRENT', 3),
    ],

    /*
    |---------------------------------------------------------------------------
    | Credit purchases
    |---------------------------------------------------------------------------
    |
    | A fake purchase: the button adds credit and no payment is taken. The
    | amounts offered are fixed server-side rather than sent by the browser,
    | because an amount a client can choose is an amount a client can forge.
    |
    */

    'credit_packs' => ['1.00', '5.00', '20.00'],

    /*
    |---------------------------------------------------------------------------
    | Webhooks
    |---------------------------------------------------------------------------
    */

    'webhooks' => [
        // Deliveries signed outside this window are rejected, which bounds how
        // long a captured request stays replayable. Each delivery attempt is
        // signed with a fresh timestamp, so a legitimate retry is never caught
        // by this.
        'tolerance_seconds' => (int) env('PIXELFORGE_WEBHOOK_TOLERANCE', 300),
    ],

    /*
    |---------------------------------------------------------------------------
    | Markup
    |---------------------------------------------------------------------------
    |
    | Applied to the provider's cost to produce the amount a user is charged,
    | in basis points. 2500 bps is 25%. This is ours, not the provider's.
    |
    */

    'markup_basis_points' => (int) env('PIXELFORGE_MARKUP_BASIS_POINTS', 2500),

    /*
    |---------------------------------------------------------------------------
    | Pricing fallback
    |---------------------------------------------------------------------------
    |
    | The provider publishes rates and its token model at GET /v1/pricing, which
    | needs no authentication, and states that rates can change and should be
    | read from that endpoint rather than hardcoded. The application caches that
    | response and prices quotes from it.
    |
    | What follows is the table as published, kept for two reasons: a quote must
    | still be possible when the pricing endpoint is unreachable, and the test
    | suite needs a fixed table to assert exact figures against.
    |
    | It is a fallback, not the source of truth. Note that a completed
    | generation is never priced from here or from the cache — it is charged on
    | the rates the provider fixed and returned on the generation itself.
    |
    | Rates are decimal strings in US dollars per million tokens. Verified
    | against the published table on 25 November 2026.
    |
    */

    'pricing' => [
        'cache_seconds' => (int) env('PIXELFORGE_PRICING_CACHE_SECONDS', 3_600),

        'fallback' => [
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',

            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],

            'tokens' => [
                // Text tokens are approximated from the prompt length. The
                // provider states the real count lands within this fraction of
                // the approximation in either direction, and is only known once
                // the generation completes.
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,

                // Exact. Reference images are never downloaded by the provider,
                // but each is billed at this many input image tokens.
                'per_reference_image' => 765,

                // Exact, by quality and size. Not an estimate.
                'output' => [
                    'low' => [
                        '1024x1024' => 280,
                        '1536x1024' => 420,
                        '1024x1536' => 420,
                    ],
                    'medium' => [
                        '1024x1024' => 1_100,
                        '1536x1024' => 1_650,
                        '1024x1536' => 1_650,
                    ],
                    'high' => [
                        '1024x1024' => 4_300,
                        '1536x1024' => 6_450,
                        '1024x1536' => 6_450,
                    ],
                ],

                // Only a range is published. How much the model reasons varies
                // per generation and is known only on completion, so a quote
                // uses the maximum.
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2_000],
                    'high' => ['min' => 1_000, 'max' => 8_000],
                ],
            ],
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Reconciliation
    |---------------------------------------------------------------------------
    |
    | Webhook delivery is explicitly not guaranteed, and GET /v1/generations/{id}
    | is the documented fallback. A generation typically waits a few seconds in
    | the queue and then processes for 5 to 30 seconds, so the poll threshold
    | sits beyond that — and beyond the 60 to 90 seconds a [slow] generation
    | takes, so the test keyword does not trigger needless polling.
    |
    */

    'reconciliation' => [
        'poll_after_seconds' => (int) env('PIXELFORGE_POLL_AFTER', 120),
        'abandon_after_seconds' => (int) env('PIXELFORGE_ABANDON_AFTER', 1_800),
    ],

];
