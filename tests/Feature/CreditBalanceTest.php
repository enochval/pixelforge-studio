<?php

declare(strict_types=1);

use App\Actions\ApplyProviderEvent;
use App\Actions\ReconcileGenerations;
use App\Actions\SubmitGeneration;
use App\Domain\GenerationRequest;
use App\Domain\LedgerEntryKind;
use App\Domain\Money;
use App\Domain\Pricing;
use App\Exceptions\InsufficientCredit;
use App\Integrations\Pixelforge\Contracts\ImageProvider;
use App\Integrations\Pixelforge\Data\ProviderGeneration;
use App\Integrations\Pixelforge\Exceptions\ContentPolicyViolation;
use App\Integrations\Pixelforge\FakeImageProvider;
use App\Integrations\Pixelforge\GenerationTranslator;
use App\Jobs\SubmitGenerationToProvider;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Support\Ledger;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;

/**
 * Credit is held, not spent.
 *
 * A quote is a ceiling, so reserving it and settling the real cost afterwards
 * is the only way a user cannot be charged more than they agreed to — and the
 * only way a failed generation ends up costing nothing rather than being
 * refunded after the fact.
 */
beforeEach(function () {
    $this->provider = new FakeImageProvider;
    $this->app->instance(ImageProvider::class, $this->provider);

    $this->user = User::factory()->create();
    app(Ledger::class)->topUp($this->user, Money::fromUsd('1.00'));
    $this->user->refresh();

    Queue::fake();
});

function aGenerationRequest(string $quality = 'medium'): GenerationRequest
{
    return new GenerationRequest('a lighthouse at dusk', '1024x1024', $quality);
}

/** The provider's view of a completed generation, for settlement. */
function completedEvent(Generation $generation): ProviderGeneration
{
    return app(GenerationTranslator::class)->fromPayload([
        'id' => $generation->provider_generation_id ?? 'gen_SETTLED',
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
    ]);
}

describe('placing a hold', function () {
    it('reserves the quoted ceiling at submission', function () {
        $generation = app(SubmitGeneration::class)->execute($this->user, aGenerationRequest());

        $hold = LedgerEntry::where('generation_id', $generation->id)->sole();

        expect($hold->kind)->toBe(LedgerEntryKind::Hold)
            ->and($hold->amount_nanos->nanos)->toBe(-$generation->quoted_nanos->nanos);

        // Reserved, not spent: the balance has moved, but nothing is charged.
        expect($this->user->fresh()->balance_nanos->nanos)
            ->toBe(1_000_000_000 - $generation->quoted_nanos->nanos)
            ->and($generation->charged_nanos)->toBeNull();
    });

    it('refuses a generation the balance cannot cover', function () {
        $poor = User::factory()->create();

        expect(fn () => app(SubmitGeneration::class)->execute($poor, aGenerationRequest()))
            ->toThrow(InsufficientCredit::class);

        // Nothing written at all, including the generation row: nothing was
        // submitted and nothing charged, so there is no history worth keeping.
        expect(Generation::count())->toBe(0)
            ->and(LedgerEntry::where('user_id', $poor->id)->count())->toBe(0)
            ->and($poor->fresh()->balance_nanos->isZero())->toBeTrue();

        Queue::assertNothingPushed();
    });

    it('reports a refusal as a field error rather than a server error', function () {
        $poor = User::factory()->create();

        actingAs($poor)
            ->from('/generations')
            ->post('/generations', ['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'high'])
            ->assertRedirect('/generations')
            ->assertSessionHasErrors('prompt');

        expect(Generation::count())->toBe(0);
    });

    it('lets a user spend down to exactly zero', function () {
        // An off-by-one here would refuse a generation a user can afford. The
        // check is >=, not >.
        $exact = User::factory()->create();
        $quote = app(Pricing::class)->quote(aGenerationRequest('low'));
        app(Ledger::class)->topUp($exact, $quote);

        $generation = app(SubmitGeneration::class)->execute($exact, aGenerationRequest('low'));

        expect($generation->exists)->toBeTrue()
            ->and($exact->fresh()->balance_nanos->isZero())->toBeTrue();
    });
});

describe('settling a hold', function () {
    it('releases the hold and charges the real cost on success', function () {
        $generation = app(SubmitGeneration::class)->execute($this->user, aGenerationRequest());
        $quote = $generation->quoted_nanos;

        app(ApplyProviderEvent::class)->execute($generation, completedEvent($generation));

        $entries = LedgerEntry::where('generation_id', $generation->id)
            ->orderBy('id')
            ->pluck('amount_nanos', 'kind');

        // Hold out, hold back, charge out. The net movement is the real cost,
        // not the ceiling.
        expect($entries)->toHaveCount(3);

        $charged = $generation->fresh()->charged_nanos;

        expect($charged->toUsdString())->toBe('0.068200000')
            ->and($charged->isGreaterThan($quote))->toBeFalse()
            ->and($this->user->fresh()->balance_nanos->nanos)->toBe(1_000_000_000 - $charged->nanos);
    });

    it('releases the hold in full and charges nothing when the generation fails', function () {
        $generation = app(SubmitGeneration::class)->execute($this->user, aGenerationRequest());

        app(ApplyProviderEvent::class)->execute($generation, app(GenerationTranslator::class)->fromPayload([
            'id' => 'gen_FAILED',
            'status' => 'failed',
            'metadata' => ['generation_id' => $generation->id],
            'error' => ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'],
        ]));

        $kinds = LedgerEntry::where('generation_id', $generation->id)->pluck('kind');

        expect($kinds)->toHaveCount(2)
            ->and($kinds->contains(LedgerEntryKind::Charge))->toBeFalse();

        // Back exactly where it started. A failed generation is free, not
        // refunded — the pair nets to zero.
        expect($this->user->fresh()->balance_nanos->toUsdString())->toBe('1.000000000')
            ->and($generation->fresh()->charged_nanos)->toBeNull();
    });

    it('releases the hold when a submission never reaches the provider', function () {
        $this->provider->failNextSubmissions(
            new ContentPolicyViolation('prompt rejected'),
        );

        $generation = app(SubmitGeneration::class)->execute($this->user, aGenerationRequest());

        (new SubmitGenerationToProvider($generation->id))->handle($this->provider);

        expect($this->user->fresh()->balance_nanos->toUsdString())->toBe('1.000000000')
            ->and(LedgerEntry::where('generation_id', $generation->id)->count())->toBe(2);
    });

    it('releases the hold when the reconciler abandons a generation', function () {
        $generation = app(SubmitGeneration::class)->execute($this->user, aGenerationRequest());
        $generation->forceFill(['created_at' => now()->subHours(2)])->saveQuietly();

        app(ReconcileGenerations::class)->execute();

        expect($generation->fresh()->failure_code)->toBe('abandoned')
            ->and($this->user->fresh()->balance_nanos->toUsdString())->toBe('1.000000000');
    });

    it('does not release the same hold twice', function () {
        // Every settling path calls release, and more than one can legitimately
        // run for the same generation — a webhook arriving as the reconciler
        // polls. The second must change nothing.
        $generation = app(SubmitGeneration::class)->execute($this->user, aGenerationRequest());

        app(Ledger::class)->release($generation);
        app(Ledger::class)->release($generation);
        app(Ledger::class)->release($generation);

        expect(LedgerEntry::where('generation_id', $generation->id)
            ->where('kind', LedgerEntryKind::HoldRelease)
            ->count())->toBe(1)
            ->and($this->user->fresh()->balance_nanos->toUsdString())->toBe('1.000000000');
    });

    it('does not charge the same generation twice', function () {
        $generation = app(SubmitGeneration::class)->execute($this->user, aGenerationRequest());
        $event = completedEvent($generation);

        app(ApplyProviderEvent::class)->execute($generation, $event);
        app(ApplyProviderEvent::class)->execute($generation->fresh(), $event);

        expect(LedgerEntry::where('generation_id', $generation->id)
            ->where('kind', LedgerEntryKind::Charge)
            ->count())->toBe(1);
    });
});

describe('the balance cache', function () {
    it('always equals the sum of the ledger', function () {
        // A randomised sequence, because the invariant has to hold across any
        // interleaving of operations rather than one chosen to make it true.
        $users = User::factory()->count(3)->create();

        foreach ($users as $user) {
            app(Ledger::class)->topUp($user, Money::fromUsd('2.00'));
        }

        for ($i = 0; $i < 30; $i++) {
            $user = $users->random();

            $action = fake()->randomElement(['submit', 'succeed', 'fail', 'topup', 'abandon']);

            if ($action === 'topup') {
                app(Ledger::class)->topUp($user, Money::fromUsd('0.50'));

                continue;
            }

            if ($action === 'submit') {
                try {
                    app(SubmitGeneration::class)->execute($user, aGenerationRequest(
                        fake()->randomElement(['low', 'medium', 'high']),
                    ));
                } catch (InsufficientCredit) {
                    // A refusal is a legitimate outcome and must leave nothing
                    // behind, which is part of what this asserts.
                }

                continue;
            }

            $open = Generation::query()
                ->where('user_id', $user->id)
                ->whereIn('status', ['creating', 'queued', 'processing'])
                ->inRandomOrder()
                ->first();

            if ($open === null) {
                continue;
            }

            if ($action === 'succeed') {
                app(ApplyProviderEvent::class)->execute($open, completedEvent($open));
            } elseif ($action === 'fail') {
                app(ApplyProviderEvent::class)->execute($open, app(GenerationTranslator::class)->fromPayload([
                    'id' => 'gen_R'.$i,
                    'status' => 'failed',
                    'metadata' => ['generation_id' => $open->id],
                    'error' => ['code' => 'generation_failed', 'message' => 'failed'],
                ]));
            } else {
                $open->forceFill(['created_at' => now()->subHours(2)])->saveQuietly();
                app(ReconcileGenerations::class)->execute();
            }
        }

        foreach ($users as $user) {
            $user->refresh();

            expect($user->balance_nanos->nanos)
                ->toBe($user->ledgerBalance()->nanos, "balance drifted for user {$user->id}");
        }

        // And the command that asserts this in the definition of done agrees.
        $this->artisan('pixelforge:verify-balances')->assertSuccessful();
    });

    it('never goes negative from a hold', function () {
        // A hold can only be placed when the balance covers it, so holds alone
        // cannot overdraw. Settlement can, and deliberately is not prevented —
        // see the ERD on why a charge is recorded rather than refused.
        $user = User::factory()->create();
        app(Ledger::class)->topUp($user, Money::fromUsd('0.05'));

        for ($i = 0; $i < 10; $i++) {
            try {
                app(SubmitGeneration::class)->execute($user, aGenerationRequest('high'));
            } catch (InsufficientCredit) {
                break;
            }
        }

        expect($user->fresh()->balance_nanos->isNegative())->toBeFalse();
    });
});
