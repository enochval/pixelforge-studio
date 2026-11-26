<?php

declare(strict_types=1);

use App\Actions\ApplyProviderEvent;
use App\Actions\BuildOperatorReport;
use App\Actions\SubmitGeneration;
use App\Domain\GenerationRequest;
use App\Domain\LedgerEntryKind;
use App\Integrations\Pixelforge\Contracts\ImageProvider;
use App\Integrations\Pixelforge\Data\GenerationSubmission;
use App\Integrations\Pixelforge\Data\ProviderGeneration;
use App\Integrations\Pixelforge\Data\SubmissionResult;
use App\Integrations\Pixelforge\Exceptions\ProviderUnavailable;
use App\Integrations\Pixelforge\FakeImageProvider;
use App\Integrations\Pixelforge\GenerationTranslator;
use App\Jobs\SubmitGenerationToProvider;
use App\Models\Generation;
use App\Models\LedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/**
 * The report answers one question that this application cannot answer alone:
 * does what we recorded match what the provider billed?
 *
 * Everything else in it is a sum of our own records, which will agree with
 * itself whether or not it is right.
 */
beforeEach(function () {
    $this->provider = new FakeImageProvider;
    $this->app->instance(ImageProvider::class, $this->provider);

    $this->user = fundedUser('5.00');

    Queue::fake();
});

function operatorReport(int $days = 7): array
{
    $to = CarbonImmutable::now('UTC');

    return app(BuildOperatorReport::class)->execute($to->subDays($days - 1), $to);
}

/**
 * Runs a generation all the way through to a charge, through the real paths.
 */
function completedGeneration(string $quality = 'medium'): Generation
{
    $generation = app(SubmitGeneration::class)->execute(
        test()->user,
        new GenerationRequest('a harbour at first light', '1024x1024', $quality),
    );

    // Submit for real so the fake holds a record of it, which is what its
    // usage endpoint is derived from.
    (new SubmitGenerationToProvider($generation->id))->handle(test()->provider);

    $generation->refresh();

    $event = test()->provider->complete($generation->provider_generation_id);

    app(ApplyProviderEvent::class)->execute($generation, $event);

    return $generation->fresh();
}

describe('the figures', function () {
    it('totals equal the ledger', function () {
        completedGeneration();
        completedGeneration('low');

        $totals = operatorReport()['totals'];

        // What the report says was charged must equal what the ledger says was
        // taken. Two sums of the same events, from different tables — if they
        // disagree, one of the two write paths is wrong.
        $chargedInLedger = LedgerEntry::query()
            ->where('kind', LedgerEntryKind::Charge)
            ->sum('amount_nanos');

        expect($totals['generations'])->toBe(2)
            ->and($totals['charged_nanos'])->toBe(-(int) $chargedInLedger);
    });

    it('reports profit as the margin between cost and charge', function () {
        completedGeneration();

        $totals = operatorReport()['totals'];

        // A subtraction, not a stored column, so it cannot disagree with the
        // two figures it comes from.
        expect($totals['profit_nanos'])
            ->toBe($totals['charged_nanos'] - $totals['provider_cost_nanos'])
            ->and($totals['profit_nanos'])->toBeGreaterThan(0);
    });

    it('groups by the day a generation completed, not the day it was created', function () {
        // The provider bills on the completion day. Grouping on creation would
        // put a generation submitted at 23:59 on the wrong side of the
        // comparison and produce a difference that looks like a charging error.
        $generation = completedGeneration();

        $generation->forceFill([
            'created_at' => CarbonImmutable::now('UTC')->subDays(3),
            'completed_at' => CarbonImmutable::now('UTC'),
        ])->saveQuietly();

        $days = collect(operatorReport()['by_day']);

        expect($days)->toHaveCount(1)
            ->and($days->first()['day'])->toBe(CarbonImmutable::now('UTC')->toDateString());
    });

    it('attributes spend to the user who made it', function () {
        completedGeneration();

        $byUser = operatorReport()['by_user'];

        expect($byUser)->toHaveCount(1)
            ->and($byUser[0]['user_id'])->toBe($this->user->id)
            ->and($byUser[0]['email'])->toBe($this->user->email);
    });

    it('counts no generation that did not succeed', function () {
        // Only successful generations are billed, so anything else in these
        // totals would be money that does not exist.
        $generation = app(SubmitGeneration::class)->execute(
            $this->user,
            new GenerationRequest('a cat', '1024x1024', 'low'),
        );

        app(ApplyProviderEvent::class)->execute($generation, app(GenerationTranslator::class)->fromPayload([
            'id' => 'gen_FAILED',
            'status' => 'failed',
            'metadata' => ['generation_id' => $generation->id],
            'error' => ['code' => 'generation_failed', 'message' => 'failed'],
        ]));

        expect(operatorReport()['totals']['generations'])->toBe(0)
            ->and(operatorReport()['totals']['charged_nanos'])->toBe(0);
    });

    it('reports zeroes for a range with nothing in it', function () {
        $totals = operatorReport()['totals'];

        expect($totals['generations'])->toBe(0)
            ->and($totals['provider_cost_nanos'])->toBe(0)
            ->and($totals['profit_nanos'])->toBe(0);
    });
});

describe('reconciliation against the provider', function () {
    it('reports zero difference when the records agree', function () {
        completedGeneration();
        completedGeneration('low');

        $check = operatorReport()['reconciliation'];

        // The fake derives its usage from the generations it actually
        // completed, so agreement here means this application priced them the
        // same way the provider did — not that both read the same number.
        expect($check['available'])->toBeTrue()
            ->and($check['agrees'])->toBeTrue()
            ->and($check['difference_nanos'])->toBe(0)
            ->and($check['recorded_nanos'])->toBe($check['provider_nanos'])
            ->and($check['recorded_nanos'])->toBeGreaterThan(0);
    });

    it('reports a difference when the provider billed for work we never recorded', function () {
        // The failure this check exists to catch, reproduced the way it would
        // actually happen: the provider ran and billed a generation that never
        // reached this application's records at all — a submission whose
        // response was lost, and whose webhook was never delivered either.
        //
        // Submitted straight through the provider, with no local row.
        $result = $this->provider->submit(new GenerationSubmission(
            request: new GenerationRequest('billed but unrecorded', '1024x1024', 'low'),
            idempotencyKey: 'orphan-key',
            clientReference: 'no-such-generation',
        ));

        $this->provider->complete($result->providerGenerationId);

        $check = operatorReport()['reconciliation'];

        expect($check['available'])->toBeTrue()
            ->and($check['agrees'])->toBeFalse()
            ->and($check['recorded_nanos'])->toBe(0)
            ->and($check['provider_nanos'])->toBeGreaterThan(0)
            // Negative: the provider billed more than we recorded, which is
            // the direction that costs real money.
            ->and($check['difference_nanos'])->toBeLessThan(0);
    });

    it('says unavailable rather than agreeing when the provider cannot be reached', function () {
        // No answer and an answer of zero mean opposite things. Conflating them
        // would turn a broken check into a passing one.
        $this->app->instance(ImageProvider::class, new class implements ImageProvider
        {
            public function submit(GenerationSubmission $submission): SubmissionResult
            {
                throw new ProviderUnavailable('down');
            }

            public function fetch(string $providerGenerationId): ProviderGeneration
            {
                throw new ProviderUnavailable('down');
            }

            public function pricing(): array
            {
                throw new ProviderUnavailable('down');
            }

            public function usage(CarbonImmutable $from, CarbonImmutable $to): array
            {
                throw new ProviderUnavailable('down');
            }
        });

        $check = operatorReport()['reconciliation'];

        expect($check['available'])->toBeFalse()
            ->and($check)->not->toHaveKey('agrees')
            ->and($check['reason'])->toBe('down');
    });
});

describe('the command', function () {
    it('prints the report', function () {
        completedGeneration();

        $this->artisan('pixelforge:report --days=2')
            ->expectsOutputToContain('Reconciliation against GET /v1/usage')
            ->assertSuccessful();
    });

    it('emits a machine-readable structure on request', function () {
        completedGeneration();

        $this->artisan('pixelforge:report --days=2 --json')->assertSuccessful();
    });

    it('succeeds on an empty range', function () {
        $this->artisan('pixelforge:report --days=1')->assertSuccessful();
    });
});
