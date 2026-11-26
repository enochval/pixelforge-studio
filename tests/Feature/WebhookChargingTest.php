<?php

declare(strict_types=1);

use App\Domain\GenerationStatus;
use App\Domain\LedgerEntryKind;
use App\Domain\Money;
use App\Domain\WebhookOutcome;
use App\Jobs\ProcessWebhookEvent;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * The claim this application exists to hold: a generation is charged exactly
 * once, whatever order its events arrive in and however many times they are
 * delivered.
 *
 * These tests drive the endpoint rather than the action behind it wherever they
 * can, because the guarantees being asserted are properties of the whole path —
 * signature, inbox, job, ledger — and testing the middle of it would leave the
 * two ends unverified.
 */
const WEBHOOK_URL = '/webhooks/pixelforge';

/**
 * The published rates, as the provider returns them on a generation.
 *
 * @var array<string, string>
 */
const PROVIDER_RATES = [
    'input_text_tokens' => '5.00',
    'input_image_tokens' => '10.00',
    'output_tokens' => '40.00',
    'reasoning_tokens' => '12.50',
];

beforeEach(function () {
    $this->user = User::factory()->create();

    $this->generation = Generation::factory()->queued()->create([
        'user_id' => $this->user->id,
        'provider_generation_id' => 'gen_'.Str::upper(Str::random(26)),
    ]);
});

/**
 * One event, in the shape the provider sends: an envelope wrapping the full
 * generation object as it stood at the moment the event happened.
 *
 * @param  array<string, mixed>  $overrides  Merged into the generation object
 * @return array<string, mixed>
 */
function providerEvent(
    Generation $generation,
    string $type = 'generation.succeeded',
    ?string $eventId = null,
    array $overrides = [],
): array {
    $status = match ($type) {
        'generation.processing' => 'processing',
        'generation.failed' => 'failed',
        default => 'succeeded',
    };

    $object = [
        'id' => $generation->provider_generation_id,
        'object' => 'generation',
        'status' => $status,
        'prompt' => $generation->prompt,
        'size' => $generation->size,
        'quality' => $generation->quality,
        'reference_images' => [],

        // Our own identifier, echoed back. This is what lets an event be
        // matched before the provider's identifier has been recorded.
        'metadata' => ['generation_id' => $generation->id],

        'image_url' => null,
        'image_url_expires_at' => null,
        'usage' => null,
        'rates' => PROVIDER_RATES,
        'error' => null,
        'created_at' => '2026-11-26T14:03:11Z',
        'started_at' => '2026-11-26T14:03:13Z',
        'completed_at' => null,
    ];

    if ($status === 'succeeded') {
        $object = array_replace($object, [
            'image_url' => 'https://images.test/'.$generation->provider_generation_id.'.png',
            'image_url_expires_at' => '2026-11-27T14:03:29Z',
            'usage' => [
                'input_text_tokens' => 12,
                'input_image_tokens' => 0,
                'output_tokens' => 1_100,
                'reasoning_tokens' => 840,
                'total_tokens' => 1_952,
            ],
            'completed_at' => '2026-11-26T14:03:29Z',
        ]);
    }

    if ($status === 'failed') {
        $object = array_replace($object, [
            'error' => ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'],
            'completed_at' => '2026-11-26T14:03:29Z',
        ]);
    }

    return [
        'id' => $eventId ?? 'evt_'.Str::upper(Str::random(26)),
        'object' => 'event',
        'type' => $type,
        'created_at' => '2026-11-26T14:03:29Z',
        'data' => ['object' => array_replace($object, $overrides)],
    ];
}

/**
 * Signs and posts a delivery the way the provider does.
 *
 * The signature is computed over the exact bytes that are sent, which is the
 * only way to exercise the real verification: signing a re-encoded copy of the
 * payload would produce a header that does not match the body.
 *
 * @param  array<string, mixed>  $event
 */
function deliver(array $event, ?int $timestamp = null, ?string $secret = null): TestResponse
{
    $body = json_encode($event, JSON_THROW_ON_ERROR);
    $timestamp ??= time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret ?? config('pixelforge.api.webhook_secret'));

    return test()->call(
        method: 'POST',
        uri: WEBHOOK_URL,
        server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'HTTP_PIXELFORGE_EVENT_ID' => $event['id'],
            'HTTP_PIXELFORGE_EVENT_TYPE' => $event['type'],
            'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => '1',
        ],
        content: $body,
    );
}

describe('verifying a delivery', function () {
    it('rejects an invalid signature and stores nothing', function () {
        // The endpoint is a public URL. A forged generation.succeeded would
        // charge a real user for work that never happened, so a delivery that
        // fails verification must not reach the inbox at all.
        $event = providerEvent($this->generation);

        deliver($event, secret: 'whsec_not_the_real_secret')->assertStatus(400);

        expect(WebhookEvent::count())->toBe(0)
            ->and(LedgerEntry::count())->toBe(0)
            ->and($this->generation->fresh()->status)->toBe(GenerationStatus::Queued);
    });

    it('rejects a replay outside the timestamp tolerance', function () {
        // A captured delivery stays correctly signed forever. Without a
        // freshness window it could be replayed indefinitely — and a replayed
        // success is a second charge.
        $event = providerEvent($this->generation);
        $tolerance = (int) config('pixelforge.webhooks.tolerance_seconds');

        deliver($event, timestamp: time() - ($tolerance + 60))->assertStatus(400);

        expect(WebhookEvent::count())->toBe(0);
    });

    it('accepts a delivery signed at the edge of the window', function () {
        // The provider signs each attempt with a fresh timestamp, so a
        // legitimate retry is never rejected by the window. A tolerance that
        // was too tight would discard real events.
        $tolerance = (int) config('pixelforge.webhooks.tolerance_seconds');

        deliver(providerEvent($this->generation), timestamp: time() - ($tolerance - 10))
            ->assertNoContent();

        expect(WebhookEvent::count())->toBe(1);
    });

    it('rejects a delivery with no signature header at all', function () {
        $this->postJson(WEBHOOK_URL, providerEvent($this->generation))->assertStatus(400);

        expect(WebhookEvent::count())->toBe(0);
    });

    it('verifies against the raw body rather than a re-encoded copy', function () {
        // Re-encoding JSON changes the bytes — key order, whitespace, unicode
        // escaping — and a signature over a re-encoded copy would not match.
        // This asserts the request is not being parsed before it is verified.
        $body = '{"id":"evt_RAW","object":"event","type":"ping","created_at":"2026-11-26T14:03:29Z","data":{}}';
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, config('pixelforge.api.webhook_secret'));

        $this->call(
            method: 'POST',
            uri: WEBHOOK_URL,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            ],
            content: $body,
        )->assertNoContent();

        expect(WebhookEvent::where('provider_event_id', 'evt_RAW')->exists())->toBeTrue();
    });

    it('is reachable without a CSRF token', function () {
        // The provider is not a browser and has no token to present. If the
        // route sat inside the web middleware group, every delivery would be
        // rejected with a 419.
        deliver(providerEvent($this->generation))->assertNoContent();
    });
});

describe('charging', function () {
    it('charges a succeeded generation once, at the rates it was created under', function () {
        deliver(providerEvent($this->generation))->assertNoContent();

        $generation = $this->generation->fresh();

        // 12 text tokens at $5/M, 1,100 output at $40/M, 840 reasoning at
        // $12.50/M: $0.054560000 exactly, then 25% markup.
        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->provider_cost_nanos->toUsdString())->toBe('0.054560000')
            ->and($generation->charged_nanos->toUsdString())->toBe('0.068200000')
            ->and($generation->image_url)->not->toBeNull()
            ->and($generation->image_url_expires_at)->not->toBeNull();

        $entries = LedgerEntry::where('generation_id', $generation->id)->get();

        expect($entries)->toHaveCount(1)
            ->and($entries->first()->kind)->toBe(LedgerEntryKind::Charge)
            ->and($entries->first()->amount_nanos->toUsdString())->toBe('-0.068200000');
    });

    it('moves the cached balance with the ledger', function () {
        $this->user->balance_nanos = Money::fromUsd('5.00');
        $this->user->save();

        deliver(providerEvent($this->generation))->assertNoContent();

        $user = $this->user->fresh();

        // The ledger is authoritative and the cached column is derived, so the
        // two have to agree. A balance that drifts is indistinguishable from a
        // charging bug once someone asks about it.
        expect($user->balance_nanos->toUsdString())->toBe('4.931800000');
    });

    it('creates no ledger entry for a failed generation', function () {
        // The provider bills only successful generations, so a failure costs
        // nothing. A schema constraint also refuses a cost on a non-succeeded
        // row, which is the same rule stated twice on purpose.
        deliver(providerEvent($this->generation, type: 'generation.failed'))->assertNoContent();

        $generation = $this->generation->fresh();

        expect($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->failure_code)->toBe('generation_failed')
            ->and($generation->charged_nanos)->toBeNull()
            ->and($generation->provider_cost_nanos)->toBeNull()
            ->and(LedgerEntry::count())->toBe(0);
    });

    it('records a processing event without charging anything', function () {
        deliver(providerEvent($this->generation, type: 'generation.processing'))->assertNoContent();

        expect($this->generation->fresh()->status)->toBe(GenerationStatus::Processing)
            ->and(LedgerEntry::count())->toBe(0);
    });
});

describe('duplicate delivery', function () {
    it('charges once when the same event is delivered twice', function () {
        // The provider delivers some events twice on purpose, even with no test
        // keyword in the prompt. This is the normal operating condition, not an
        // edge case.
        $event = providerEvent($this->generation, eventId: 'evt_THESAMEEVENT');

        deliver($event)->assertNoContent();
        deliver($event)->assertNoContent();

        expect(WebhookEvent::count())->toBe(1)
            ->and(LedgerEntry::where('generation_id', $this->generation->id)->count())->toBe(1);
    });

    it('answers 2xx to a duplicate rather than reporting it as an error', function () {
        // Anything other than a 2xx is a signal to redeliver. Treating a
        // duplicate as an error would have the provider retry it six times.
        $event = providerEvent($this->generation, eventId: 'evt_DUPLICATE');

        deliver($event)->assertNoContent();
        deliver($event)->assertNoContent();
    });

    it('charges once when a resend arrives as a later delivery attempt', function () {
        // The dashboard's resend button sends a new *delivery* of the same
        // event. Deduplicating on the delivery attempt rather than the event id
        // would let this through as new work.
        $event = providerEvent($this->generation, eventId: 'evt_RESENT');
        $body = json_encode($event, JSON_THROW_ON_ERROR);

        foreach ([1, 2, 3] as $attempt) {
            $timestamp = time();
            $signature = hash_hmac('sha256', $timestamp.'.'.$body, config('pixelforge.api.webhook_secret'));

            $this->call(
                method: 'POST',
                uri: WEBHOOK_URL,
                server: [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
                    'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => (string) $attempt,
                ],
                content: $body,
            )->assertNoContent();
        }

        expect(WebhookEvent::count())->toBe(1)
            ->and(LedgerEntry::count())->toBe(1);
    });
});

describe('out-of-order delivery', function () {
    it('does not regress a succeeded generation when processing arrives late', function () {
        // The provider documents this directly: a retried generation.processing
        // can arrive after generation.succeeded.
        deliver(providerEvent($this->generation, type: 'generation.succeeded'))->assertNoContent();
        deliver(providerEvent($this->generation, type: 'generation.processing'))->assertNoContent();

        $generation = $this->generation->fresh();

        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->charged_nanos)->not->toBeNull()
            ->and(LedgerEntry::count())->toBe(1);

        // The late event is recorded as deliberately ignored, not silently
        // dropped: "received and superseded" and "never processed" have to be
        // distinguishable afterwards.
        $late = WebhookEvent::where('event_type', 'generation.processing')->sole();

        expect($late->outcome)->toBe(WebhookOutcome::IgnoredStale)
            ->and($late->isProcessed())->toBeTrue();
    });

    it('never revises a terminal outcome', function () {
        deliver(providerEvent($this->generation, type: 'generation.failed'))->assertNoContent();
        deliver(providerEvent($this->generation, type: 'generation.succeeded'))->assertNoContent();

        // A generation that failed stays failed. Accepting the later success
        // would charge for a generation already reported to the user as free.
        expect($this->generation->fresh()->status)->toBe(GenerationStatus::Failed)
            ->and(LedgerEntry::count())->toBe(0);
    });

    it('applies a terminal event that skips a stage', function () {
        // Stages may be skipped: the processing notification can be lost
        // entirely, and a generation must still be able to complete.
        expect($this->generation->status)->toBe(GenerationStatus::Queued);

        deliver(providerEvent($this->generation, type: 'generation.succeeded'))->assertNoContent();

        expect($this->generation->fresh()->status)->toBe(GenerationStatus::Succeeded);
    });
});

describe('an event with no generation to match', function () {
    it('keeps an event that arrives before the generation is identifiable', function () {
        // The documented race: the provider can dispatch an event before the
        // response to the submission has been written.
        $orphan = Generation::factory()->make([
            'provider_generation_id' => 'gen_'.Str::upper(Str::random(26)),
        ]);

        $event = providerEvent($orphan);
        $event['data']['object']['metadata'] = [];

        deliver($event)->assertNoContent();

        $stored = WebhookEvent::sole();

        // Stored and left unprocessed, so it stays in the index the reconciler
        // scans. Discarding it would lose a paid generation.
        expect($stored->outcome)->toBe(WebhookOutcome::Orphaned)
            ->and($stored->isProcessed())->toBeFalse()
            ->and(LedgerEntry::count())->toBe(0);
    });

    it('matches on our own identifier before the provider id is recorded', function () {
        // A generation still in `creating`: accepted locally, no provider id
        // yet. The metadata we sent is the only way to match this event.
        $generation = Generation::factory()->create([
            'user_id' => $this->user->id,
            'provider_generation_id' => null,
            'status' => GenerationStatus::Creating,
        ]);

        $event = providerEvent($generation);
        $event['data']['object']['id'] = 'gen_NOTYETRECORDED';

        deliver($event)->assertNoContent();

        expect(WebhookEvent::sole()->outcome)->toBe(WebhookOutcome::Applied)
            ->and($generation->fresh()->status)->toBe(GenerationStatus::Succeeded)
            ->and(LedgerEntry::count())->toBe(1);
    });

    it('acknowledges a ping, which is about no generation', function () {
        $ping = [
            'id' => 'evt_PING',
            'object' => 'event',
            'type' => 'ping',
            'created_at' => '2026-11-26T14:03:29Z',
            'data' => [],
        ];

        deliver($ping)->assertNoContent();

        $stored = WebhookEvent::sole();

        expect($stored->outcome)->toBe(WebhookOutcome::Acknowledged)
            ->and($stored->isProcessed())->toBeTrue()
            ->and($stored->provider_generation_id)->toBeNull()
            ->and(LedgerEntry::count())->toBe(0);
    });
});

describe('the request itself', function () {
    it('does no charging inside the request', function () {
        // Any 2xx within ten seconds counts as delivered, and anything slower
        // is redelivered. Work that happens in the request is work that can
        // time out and be delivered again.
        Queue::fake();

        deliver(providerEvent($this->generation))->assertNoContent();

        Queue::assertPushed(ProcessWebhookEvent::class);

        expect(WebhookEvent::count())->toBe(1)
            ->and(LedgerEntry::count())->toBe(0)
            ->and($this->generation->fresh()->status)->toBe(GenerationStatus::Queued);
    });

    it('records the raw delivery as it arrived', function () {
        // When a figure is questioned months later, what the provider actually
        // said about that generation is on file rather than reconstructed.
        deliver(providerEvent($this->generation, eventId: 'evt_KEPT'))->assertNoContent();

        $stored = WebhookEvent::sole();

        expect($stored->provider_event_id)->toBe('evt_KEPT')
            ->and($stored->event_type)->toBe('generation.succeeded')
            ->and($stored->payload['data']['object']['usage']['output_tokens'])->toBe(1_100);
    });
});

describe('the schema as the last line of defence', function () {
    it('refuses a second charge at the database even if the guard is bypassed', function () {
        // The guard above is runtime behaviour that a refactor could remove
        // without any test noticing. The unique index is a property of the
        // schema, and it is what makes a double charge impossible rather than
        // merely unlikely.
        $generation = Generation::factory()->succeeded()->create(['user_id' => $this->user->id]);

        LedgerEntry::create([
            'user_id' => $this->user->id,
            'generation_id' => $generation->id,
            'kind' => LedgerEntryKind::Charge,
            'amount_nanos' => Money::fromUsd('-0.068200000'),
            'created_at' => now(),
        ]);

        expect(fn () => LedgerEntry::create([
            'user_id' => $this->user->id,
            'generation_id' => $generation->id,
            'kind' => LedgerEntryKind::Charge,
            'amount_nanos' => Money::fromUsd('-0.068200000'),
            'created_at' => now(),
        ]))->toThrow(UniqueConstraintViolationException::class);
    });
});
