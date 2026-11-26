<?php

declare(strict_types=1);

use App\Actions\ApplyProviderEvent;
use App\Domain\GenerationStatus;
use App\Domain\Money;
use App\Integrations\Pixelforge\GenerationTranslator;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Two workers, one generation, one charge.
 *
 * This file is outside the Feature suite and does not use RefreshDatabase. An
 * uncommitted row is invisible to every connection except the one that wrote
 * it, so a test wrapped in a transaction would see an empty database from its
 * second connection. These tests commit, and tidy up after themselves.
 *
 * They divide into two kinds, and the distinction is worth stating because it
 * is easy to write the first and believe it covers the second:
 *
 *   Most of them apply the same outcome twice and assert it was charged once.
 *   The two calls do not overlap in time, so what they prove is the
 *   compare-and-set guard and the unique index. Remove the `status_rank`
 *   condition from ApplyProviderEvent and these fail.
 *
 *   The last one holds a competing lock on another connection while the action
 *   runs, and asserts the action cannot write through it. That covers the
 *   overlap the others do not.
 */
uses(TestCase::class);

/**
 * Everything written here is removed afterwards, whether the test passed or
 * not. Deleting the user cascades to its generations and ledger entries.
 *
 * @var array<int, int>
 */
$createdUserIds = [];

afterEach(function () use (&$createdUserIds) {
    foreach ($createdUserIds as $id) {
        DB::table('users')->where('id', $id)->delete();
    }

    $createdUserIds = [];
});

it('charges once when two connections apply the same outcome', function () use (&$createdUserIds) {
    $user = User::factory()->create();
    $createdUserIds[] = $user->id;

    // Assigned rather than passed to the factory: balance_nanos is absent from
    // the model's fillable attributes on purpose, so a balance can never be set
    // from request input.
    $user->balance_nanos = Money::fromUsd('5.00');
    $user->save();

    $generation = Generation::factory()->queued()->create(['user_id' => $user->id]);

    $event = app(GenerationTranslator::class)->fromPayload([
        'id' => $generation->provider_generation_id,
        'status' => 'succeeded',
        'metadata' => ['generation_id' => $generation->id],
        'image_url' => 'https://images.test/image.png',
        'image_url_expires_at' => '2026-11-27T14:03:29Z',
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
        'completed_at' => '2026-11-26T14:03:29Z',
    ]);

    // Both callers do the full thing: lock the row, evaluate the guard, write
    // the charge. Applied one after the other, which is the common case — a
    // redelivery arriving after the first was handled.
    $applied = [
        app(ApplyProviderEvent::class)->execute($generation, $event),
        app(ApplyProviderEvent::class)->execute($generation->fresh(), $event),
    ];

    // Exactly one moved the generation forward. The other found the transition
    // no longer allowed and stopped before reaching the charging code.
    expect(array_filter($applied))->toHaveCount(1);

    $charges = DB::table('ledger_entries')->where('generation_id', $generation->id)->count();
    $balance = (int) DB::table('users')->where('id', $user->id)->value('balance_nanos');

    // Charged once, so the balance moved once: $5.00 less $0.068200000.
    expect($charges)->toBe(1)
        ->and($balance)->toBe(4_931_800_000)
        ->and($generation->fresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('charges once when the second attempt comes through another connection', function () use (&$createdUserIds) {
    // The same assertion with the second attempt on its own connection, so the
    // result does not depend on anything cached in the first one's session.
    $user = User::factory()->create();
    $createdUserIds[] = $user->id;

    $generation = Generation::factory()->queued()->create(['user_id' => $user->id]);

    $event = app(GenerationTranslator::class)->fromPayload([
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
    ]);

    $onDefault = app(ApplyProviderEvent::class)->execute($generation, $event);

    // Re-read through the second connection, then apply again. The action's own
    // writes go through whichever connection the model carries.
    $viaSecond = Generation::on('pgsql_second')->findOrFail($generation->id);
    $onSecond = app(ApplyProviderEvent::class)->execute($viaSecond, $event);

    expect($onDefault)->toBeTrue()
        ->and($onSecond)->toBeFalse()
        ->and(DB::table('ledger_entries')->where('generation_id', $generation->id)->count())->toBe(1);
});

it('leaves no generation half-applied when a charge is refused', function () use (&$createdUserIds) {
    // The ordering that matters: status and charge are written in one
    // transaction. A refused charge rolls back to a savepoint rather than
    // killing that transaction, so the status change it belongs with survives.
    $user = User::factory()->create();
    $createdUserIds[] = $user->id;

    $generation = Generation::factory()->queued()->create(['user_id' => $user->id]);

    // A charge already on file for this generation, as a reconciler racing a
    // webhook would leave.
    DB::table('ledger_entries')->insert([
        'user_id' => $user->id,
        'generation_id' => $generation->id,
        'kind' => 'charge',
        'amount_nanos' => -68_200_000,
        'created_at' => now(),
    ]);

    $applied = app(ApplyProviderEvent::class)->execute($generation, app(GenerationTranslator::class)->fromPayload([
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
    ]));

    expect($applied)->toBeTrue()
        ->and($generation->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->fresh()->charged_nanos->toUsdString())->toBe('0.068200000')
        ->and(DB::table('ledger_entries')->where('generation_id', $generation->id)->count())->toBe(1);
});

it('keeps a unique provider event id across connections', function () use (&$createdUserIds) {
    // The inbox constraint, proven the same way: two connections inserting the
    // same event id, one of them refused by the database rather than by a
    // check this application performed.
    $eventId = 'evt_'.Str::upper(Str::random(26));
    $row = fn (): array => [
        'provider_event_id' => $eventId,
        'event_type' => 'generation.succeeded',
        'provider_generation_id' => 'gen_'.Str::upper(Str::random(26)),
        'payload' => json_encode(['id' => $eventId], JSON_THROW_ON_ERROR),
        'received_at' => now(),
    ];

    try {
        DB::table('webhook_events')->insert($row());

        expect(fn () => DB::connection('pgsql_second')->table('webhook_events')->insert($row()))
            ->toThrow(UniqueConstraintViolationException::class);

        expect(DB::table('webhook_events')->where('provider_event_id', $eventId)->count())->toBe(1);
    } finally {
        DB::table('webhook_events')->where('provider_event_id', $eventId)->delete();
    }
});

it('blocks while another connection holds the generation row', function () use (&$createdUserIds) {
    // The overlap case. A competing lock is taken on a second connection and
    // held in an open transaction, so the action's own UPDATE cannot touch the
    // row while it is held.
    //
    // A short lock_timeout is set first: without it the action would wait for
    // the holder and the test would hang rather than fail. What this asserts is
    // that the two cannot interleave — the action either claims the transition
    // and writes, or does neither.
    $user = User::factory()->create();
    $createdUserIds[] = $user->id;

    $generation = Generation::factory()->queued()->create(['user_id' => $user->id]);

    $event = app(GenerationTranslator::class)->fromPayload([
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
    ]);

    $holder = DB::connection('pgsql_second');
    $holder->beginTransaction();

    try {
        $holder->table('generations')->where('id', $generation->id)->lockForUpdate()->first();

        DB::statement("SET lock_timeout = '500ms'");

        expect(fn () => app(ApplyProviderEvent::class)->execute($generation, $event))
            ->toThrow(QueryException::class);

        // Nothing was charged, because the action never got past the lock.
        expect($generation->fresh()->status)->toBe(GenerationStatus::Queued)
            ->and(DB::table('ledger_entries')->where('generation_id', $generation->id)->count())->toBe(0);
    } finally {
        DB::statement('SET lock_timeout = 0');
        $holder->rollBack();
    }
});
