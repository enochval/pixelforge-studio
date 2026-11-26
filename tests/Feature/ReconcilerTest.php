<?php

declare(strict_types=1);

use App\Actions\ReconcileGenerations;
use App\Domain\GenerationRequest;
use App\Domain\GenerationStatus;
use App\Domain\TokenUsage;
use App\Domain\WebhookOutcome;
use App\Integrations\Pixelforge\Contracts\ImageProvider;
use App\Integrations\Pixelforge\Data\GenerationSubmission;
use App\Integrations\Pixelforge\FakeImageProvider;
use App\Jobs\SubmitGenerationToProvider;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * The reconciler exists because the provider says delivery is not guaranteed.
 * These tests are about what happens when a webhook never comes.
 */
beforeEach(function () {
    $this->provider = new FakeImageProvider;
    $this->app->instance(ImageProvider::class, $this->provider);

    $this->user = User::factory()->create();

    Queue::fake();
});

function reconcile(): array
{
    return app(ReconcileGenerations::class)->execute();
}

/**
 * A generation the provider accepted, submitted long enough ago that no
 * outcome should still be pending.
 */
function staleGeneration(User $user, string $status = 'queued'): Generation
{
    $generation = Generation::factory()->{$status}()->create([
        'user_id' => $user->id,
        'provider_generation_id' => 'gen_'.Str::upper(Str::random(26)),
    ]);

    // Past the poll threshold. Written directly so the test does not depend on
    // travelling in time, which would also move the thresholds themselves.
    $generation->forceFill([
        'created_at' => now()->subMinutes(10),
        'submitted_at' => now()->subMinutes(10),
    ])->saveQuietly();

    return $generation->fresh();
}

describe('a generation whose webhook never arrived', function () {
    it('polls the provider and charges the outcome it finds', function () {
        $generation = staleGeneration($this->user);

        // The provider finished it; we were simply never told.
        $this->provider->advanceToProcessing($generation->provider_generation_id);
        $this->provider->complete($generation->provider_generation_id, new TokenUsage(
            inputTextTokens: 12,
            inputImageTokens: 0,
            outputTokens: 1_100,
            reasoningTokens: 840,
        ));

        expect(reconcile()['polled'])->toBe(1);

        $generation->refresh();

        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->charged_nanos->toUsdString())->toBe('0.068200000')
            ->and(LedgerEntry::where('generation_id', $generation->id)->count())->toBe(1);
    });

    it('prices a reconciled generation exactly as a webhook would have', function () {
        // The guarantee that makes one implementation worth insisting on: a
        // generation resolved by polling and one resolved by a webhook cost the
        // same, because the same action applies both.
        $viaPoll = staleGeneration($this->user);
        $this->provider->complete($viaPoll->provider_generation_id);

        reconcile();

        $viaWebhook = Generation::factory()->succeeded()->create(['user_id' => $this->user->id]);

        expect($viaPoll->fresh()->charged_nanos->toUsdString())
            ->toBe($viaWebhook->charged_nanos->toUsdString());
    });

    it('records a failure the provider reports, and charges nothing', function () {
        $generation = staleGeneration($this->user);
        $this->provider->fail($generation->provider_generation_id);

        reconcile();

        expect($generation->fresh()->status)->toBe(GenerationStatus::Failed)
            ->and($generation->fresh()->charged_nanos)->toBeNull()
            ->and(LedgerEntry::count())->toBe(0);
    });

    it('leaves a generation alone while it is still simply running', function () {
        // Submitted seconds ago, not minutes. The provider takes 5 to 30
        // seconds, so polling here would be chasing work that is on time.
        $fresh = Generation::factory()->queued()->create(['user_id' => $this->user->id]);
        $this->provider->complete($fresh->provider_generation_id);

        expect(reconcile()['polled'])->toBe(0)
            ->and($fresh->fresh()->status)->toBe(GenerationStatus::Queued);
    });

    it('never touches a generation that already finished', function () {
        $done = Generation::factory()->succeeded()->create(['user_id' => $this->user->id]);
        $done->forceFill(['submitted_at' => now()->subMinutes(10)])->saveQuietly();

        expect(reconcile()['polled'])->toBe(0);
    });

    it('does not charge twice when a late webhook and the reconciler collide', function () {
        // Both paths resolving the same generation is the expected case, not an
        // exotic one: the reconciler polls at the moment a delayed delivery
        // finally arrives. The compare-and-set guard means whichever is second
        // changes nothing.
        $generation = staleGeneration($this->user);
        $this->provider->complete($generation->provider_generation_id);

        reconcile();
        reconcile();

        expect(LedgerEntry::where('generation_id', $generation->id)->count())->toBe(1);
    });
});

describe('an event stored before its generation could be identified', function () {
    it('matches the orphan and applies it without calling the provider', function () {
        $generation = Generation::factory()->queued()->create(['user_id' => $this->user->id]);

        $event = WebhookEvent::factory()->create([
            'provider_generation_id' => $generation->provider_generation_id,
            'outcome' => WebhookOutcome::Orphaned,
            'processed_at' => null,
            'payload' => ['data' => ['object' => [
                'id' => $generation->provider_generation_id,
                'status' => 'succeeded',
                'metadata' => ['generation_id' => $generation->id],
                'usage' => [
                    'input_text_tokens' => 12,
                    'input_image_tokens' => 0,
                    'output_tokens' => 1_100,
                    'reasoning_tokens' => 840,
                ],
                'rates' => [
                    'input_text_tokens' => '5.00',
                    'input_image_tokens' => '10.00',
                    'output_tokens' => '40.00',
                    'reasoning_tokens' => '12.50',
                ],
            ]]],
        ]);

        expect(reconcile()['orphans_matched'])->toBe(1);

        // The stored payload was enough. Nothing was polled, because an event
        // already in hand is better evidence than anything a request returns.
        expect($this->provider->generationCount())->toBe(0);

        $event->refresh();

        expect($event->outcome)->toBe(WebhookOutcome::Applied)
            ->and($event->isProcessed())->toBeTrue()
            ->and($generation->fresh()->status)->toBe(GenerationStatus::Succeeded)
            ->and(LedgerEntry::count())->toBe(1);
    });

    it('keeps an orphan that still cannot be matched', function () {
        // The generation may yet appear. If it never does, this row is the
        // evidence that the provider billed for work with no local record.
        $event = WebhookEvent::factory()->create([
            'provider_generation_id' => 'gen_NEVERSEEN',
            'outcome' => WebhookOutcome::Orphaned,
            'processed_at' => null,
            'payload' => ['data' => ['object' => ['id' => 'gen_NEVERSEEN', 'status' => 'succeeded']]],
        ]);

        expect(reconcile()['orphans_matched'])->toBe(0);

        expect($event->fresh()->outcome)->toBe(WebhookOutcome::Orphaned)
            ->and($event->fresh()->isProcessed())->toBeFalse();
    });

    it('marks an orphan superseded rather than applying it twice', function () {
        $generation = Generation::factory()->succeeded()->create(['user_id' => $this->user->id]);

        $event = WebhookEvent::factory()->create([
            'provider_generation_id' => $generation->provider_generation_id,
            'event_type' => 'generation.processing',
            'outcome' => WebhookOutcome::Orphaned,
            'processed_at' => null,
            'payload' => ['data' => ['object' => [
                'id' => $generation->provider_generation_id,
                'status' => 'processing',
                'metadata' => ['generation_id' => $generation->id],
            ]]],
        ]);

        reconcile();

        expect($event->fresh()->outcome)->toBe(WebhookOutcome::IgnoredStale)
            ->and($generation->fresh()->status)->toBe(GenerationStatus::Succeeded);
    });

    it('ignores events that have already been processed', function () {
        WebhookEvent::factory()->create([
            'outcome' => WebhookOutcome::Applied,
            'processed_at' => now(),
        ]);

        expect(reconcile()['orphans_matched'])->toBe(0);
    });
});

describe('a generation the provider never accepted', function () {
    it('resubmits it rather than closing it', function () {
        // Every submission attempt failed or timed out. A timeout is an unknown
        // outcome, not a failed one — the request may have been accepted — so
        // the right move is to ask again, not to give up.
        $stuck = Generation::factory()->create([
            'user_id' => $this->user->id,
            'status' => GenerationStatus::Creating,
            'provider_generation_id' => null,
        ]);

        $stuck->forceFill(['created_at' => now()->subMinutes(10)])->saveQuietly();

        expect(reconcile()['resubmitted'])->toBe(1);

        Queue::assertPushed(SubmitGenerationToProvider::class);

        // Still open, because resubmitting is the attempt to resolve it.
        expect($stuck->fresh()->status)->toBe(GenerationStatus::Creating);
    });

    it('presents the same idempotency key, so a resubmission cannot create a second generation', function () {
        // This is what makes resubmission safe at all. Without the key stored
        // on the row before the first attempt, every ambiguous timeout would
        // risk a second paid generation.
        $stuck = Generation::factory()->create([
            'user_id' => $this->user->id,
            'status' => GenerationStatus::Creating,
            'provider_generation_id' => null,
        ]);
        $stuck->forceFill(['created_at' => now()->subMinutes(10)])->saveQuietly();

        $key = $stuck->idempotency_key;

        // The provider already accepted an earlier attempt under this key.
        $this->provider->submit(new GenerationSubmission(
            request: new GenerationRequest($stuck->prompt, $stuck->size, $stuck->quality),
            idempotencyKey: $key,
            clientReference: $stuck->id,
        ));

        reconcile();

        // Run the resubmission the reconciler queued.
        (new SubmitGenerationToProvider($stuck->id))->handle($this->provider);

        expect($this->provider->generationCount())->toBe(1)
            ->and($stuck->fresh()->idempotency_key)->toBe($key)
            ->and($stuck->fresh()->provider_generation_id)->not->toBeNull();
    });

    it('does not resubmit one that is already past abandoning', function () {
        $ancient = Generation::factory()->create([
            'user_id' => $this->user->id,
            'status' => GenerationStatus::Creating,
            'provider_generation_id' => null,
        ]);
        $ancient->forceFill(['created_at' => now()->subHours(2)])->saveQuietly();

        $result = reconcile();

        expect($result['resubmitted'])->toBe(0)
            ->and($result['abandoned'])->toBe(1);

        Queue::assertNotPushed(SubmitGenerationToProvider::class);
    });
});

describe('a generation nothing is coming for', function () {
    it('closes it as failed, charging nothing', function () {
        $ancient = staleGeneration($this->user);
        $ancient->forceFill(['created_at' => now()->subHours(2)])->saveQuietly();

        // The provider still knows it and still has not finished it, two hours
        // on. Polling therefore returns nothing new and there is nothing left
        // to wait for.
        $this->provider->advanceToProcessing($ancient->provider_generation_id);

        expect(reconcile()['abandoned'])->toBe(1);

        $ancient->refresh();

        expect($ancient->status)->toBe(GenerationStatus::Failed)
            ->and($ancient->failure_code)->toBe('abandoned')
            ->and($ancient->charged_nanos)->toBeNull()
            ->and($ancient->completed_at)->not->toBeNull()
            ->and(LedgerEntry::count())->toBe(0);
    });

    it('stops polling it once abandoned', function () {
        // Left open, these rows would be returned by every subsequent run and
        // the work would grow without bound.
        $ancient = staleGeneration($this->user);
        $ancient->forceFill(['created_at' => now()->subHours(2)])->saveQuietly();
        $this->provider->advanceToProcessing($ancient->provider_generation_id);

        reconcile();

        expect(reconcile())->toMatchArray(['polled' => 0, 'abandoned' => 0]);
    });

    it('closes a generation the provider has no record of', function () {
        // An id this application holds that the provider does not recognise.
        // Nothing to wait for, so it is closed rather than polled for ever.
        $generation = staleGeneration($this->user);

        expect(reconcile()['polled'])->toBe(0);

        expect($generation->fresh()->status)->toBe(GenerationStatus::Failed)
            ->and($generation->fresh()->failure_code)->toBe('not_found_at_provider')
            ->and(LedgerEntry::count())->toBe(0);
    });
});

describe('the command', function () {
    it('reports what it did', function () {
        $generation = staleGeneration($this->user);
        $this->provider->complete($generation->provider_generation_id);

        $this->artisan('pixelforge:reconcile')
            ->expectsOutputToContain('polled')
            ->assertSuccessful();
    });

    it('succeeds when there is nothing to do', function () {
        $this->artisan('pixelforge:reconcile')->assertSuccessful();

        expect(Generation::count())->toBe(0);
    });
});

describe('not repeating itself', function () {
    it('does not resubmit the same generation on every run', function () {
        // Nothing about the row changes when a resubmission is queued, so
        // without a cooldown this generation would be queued again every minute
        // for the half hour before it is abandoned — about thirty requests
        // against a 60-per-minute allowance, to no benefit.
        $stuck = Generation::factory()->create([
            'user_id' => $this->user->id,
            'status' => GenerationStatus::Creating,
            'provider_generation_id' => null,
        ]);
        $stuck->forceFill(['created_at' => now()->subMinutes(10)])->saveQuietly();

        expect(reconcile()['resubmitted'])->toBe(1)
            ->and(reconcile()['resubmitted'])->toBe(0)
            ->and(reconcile()['resubmitted'])->toBe(0);

        Queue::assertPushed(SubmitGenerationToProvider::class, 1);
    });

    it('tries again once the cooldown has passed', function () {
        $stuck = Generation::factory()->create([
            'user_id' => $this->user->id,
            'status' => GenerationStatus::Creating,
            'provider_generation_id' => null,
        ]);
        $stuck->forceFill(['created_at' => now()->subMinutes(10)])->saveQuietly();

        reconcile();

        // A cooldown, not a one-shot: a generation that is still unaccepted
        // deserves another attempt, just not immediately.
        Cache::forget("pixelforge:resubmitted:{$stuck->id}");

        expect(reconcile()['resubmitted'])->toBe(1);
    });
});
