<?php

declare(strict_types=1);

use App\Domain\GenerationStatus;
use App\Domain\LedgerEntryKind;
use App\Domain\Money;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * These assert the database, not the code above it.
 *
 * The guarantees this application makes about money are enforced by
 * constraints, so they are tested by attempting the writes those constraints
 * exist to refuse. If a constraint is later dropped, one of these fails rather
 * than money quietly going missing.
 */

/**
 * Attempts a write and reports whether the database refused it.
 *
 * Postgres aborts the surrounding transaction when a statement fails, and
 * RefreshDatabase runs each test inside one — so a bare expectation that a
 * write throws leaves the connection unusable for anything that follows.
 * Running it in a nested transaction creates a savepoint, and the failure
 * rolls back only to there.
 */
function databaseRefuses(Closure $write): bool
{
    try {
        DB::transaction($write);

        return false;
    } catch (QueryException) {
        return true;
    }
}
describe('financial history cannot be severed', function () {
    it('refuses to delete a generation that carries ledger entries', function () {
        // What the operator report depends on: a provider cost cannot be made
        // to disappear from our records by deleting the row it belongs to.
        //
        // The foreign key is nullOnDelete, but a check constraint requires
        // every entry other than a top-up to name a generation — so severing
        // one is refused, and the delete fails with it. Two constraints
        // combining to make financial history immovable.
        $generation = Generation::factory()->succeeded()->create();

        LedgerEntry::factory()->create([
            'user_id' => $generation->user_id,
            'generation_id' => $generation->id,
        ]);

        expect(databaseRefuses(fn () => $generation->delete()))->toBeTrue()
            ->and(Generation::whereKey($generation->id)->exists())->toBeTrue();
    });

    it('allows deleting a generation that has no entries', function () {
        // Which is what makes a refused submission cleanable: the hold rolled
        // back, so there is nothing to keep.
        $generation = Generation::factory()->create();

        expect(databaseRefuses(fn () => $generation->delete()))->toBeFalse()
            ->and(Generation::whereKey($generation->id)->exists())->toBeFalse();
    });
});

describe('duplicate delivery', function () {
    it('refuses a second webhook event with the same provider event id', function () {
        WebhookEvent::factory()->create(['provider_event_id' => 'evt_duplicate']);

        expect(databaseRefuses(fn () => WebhookEvent::factory()->create([
            'provider_event_id' => 'evt_duplicate',
        ])))->toBeTrue();

        expect(WebhookEvent::where('provider_event_id', 'evt_duplicate')->count())->toBe(1);
    });

    it('accepts two distinct deliveries for the same generation', function () {
        $providerGenerationId = 'gen_same';

        WebhookEvent::factory()->create([
            'provider_event_id' => 'evt_one',
            'event_type' => 'generation.processing',
            'provider_generation_id' => $providerGenerationId,
        ]);

        WebhookEvent::factory()->create([
            'provider_event_id' => 'evt_two',
            'event_type' => 'generation.succeeded',
            'provider_generation_id' => $providerGenerationId,
        ]);

        expect(WebhookEvent::where('provider_generation_id', $providerGenerationId)->count())->toBe(2);
    });
});

describe('charging exactly once', function () {
    it('refuses a second charge for the same generation', function () {
        $generation = Generation::factory()->succeeded()->create();

        LedgerEntry::factory()->create([
            'user_id' => $generation->user_id,
            'generation_id' => $generation->id,
            'kind' => LedgerEntryKind::Charge,
        ]);

        expect(databaseRefuses(fn () => LedgerEntry::factory()->create([
            'user_id' => $generation->user_id,
            'generation_id' => $generation->id,
            'kind' => LedgerEntryKind::Charge,
        ])))->toBeTrue();

        expect(LedgerEntry::where('generation_id', $generation->id)->count())->toBe(1);
    });

    it('allows the distinct entries that settle one generation', function () {
        $generation = Generation::factory()->succeeded()->create();
        $attributes = ['user_id' => $generation->user_id, 'generation_id' => $generation->id];

        LedgerEntry::factory()->hold()->create($attributes);
        LedgerEntry::factory()->holdRelease()->create($attributes);
        LedgerEntry::factory()->create($attributes);

        expect(LedgerEntry::where('generation_id', $generation->id)->count())->toBe(3);
    });

    it('allows a user several credit purchases', function () {
        $user = User::factory()->create();

        LedgerEntry::factory()->topup()->count(3)->create(['user_id' => $user->id]);

        // The uniqueness rule is partial: it applies only where a generation is
        // present, so top-ups are not constrained by it.
        expect(LedgerEntry::where('user_id', $user->id)->count())->toBe(3);
    });
});

describe('events for generations that cannot yet be identified', function () {
    it('stores a webhook event whose generation is unknown', function () {
        // The provider can dispatch its first event before the response to the
        // submission has been written. A foreign key here would reject exactly
        // that event, so the column deliberately has none.
        $event = WebhookEvent::factory()->create([
            'provider_generation_id' => 'gen_not_yet_recorded',
        ]);

        expect($event->exists)->toBeTrue()
            ->and(Generation::where('provider_generation_id', 'gen_not_yet_recorded')->exists())->toBeFalse();
    });
});

describe('vocabulary and direction', function () {
    it('refuses a status outside the known lifecycle', function () {
        $generation = Generation::factory()->create();

        expect(databaseRefuses(fn () => DB::table('generations')
            ->where('id', $generation->id)
            ->update(['status' => 'cancelled'])))->toBeTrue();
    });

    it('refuses a ledger entry whose amount contradicts its kind', function (string $kind, int $nanos) {
        $generation = Generation::factory()->create();

        expect(databaseRefuses(fn () => DB::table('ledger_entries')->insert([
            'user_id' => $generation->user_id,
            'generation_id' => $generation->id,
            'kind' => $kind,
            'amount_nanos' => $nanos,
            'created_at' => now(),
        ])))->toBeTrue();
    })->with([
        'a charge that credits the user' => ['charge', 1_000],
        'a hold that credits the user' => ['hold', 1_000],
        'a release that debits the user' => ['hold_release', -1_000],
        'a top-up that debits the user' => ['topup', -1_000],
    ]);

    it('refuses an entry other than a top-up with no generation', function () {
        $user = User::factory()->create();

        expect(databaseRefuses(fn () => DB::table('ledger_entries')->insert([
            'user_id' => $user->id,
            'generation_id' => null,
            'kind' => 'charge',
            'amount_nanos' => -1_000,
            'created_at' => now(),
        ])))->toBeTrue();
    });
});

describe('costs and outcome agree', function () {
    it('refuses a succeeded generation with no recorded cost', function () {
        $generation = Generation::factory()->create();

        expect(databaseRefuses(fn () => DB::table('generations')->where('id', $generation->id)->update([
            'status' => 'succeeded',
            'status_rank' => 3,
        ])))->toBeTrue();
    });

    it('refuses a failed generation that carries a charge', function () {
        $generation = Generation::factory()->create();

        expect(databaseRefuses(fn () => DB::table('generations')->where('id', $generation->id)->update([
            'status' => 'failed',
            'status_rank' => 3,
            'provider_cost_nanos' => 1_000,
            'charged_nanos' => 1_250,
        ])))->toBeTrue();
    });

    it('records the quote, the provider cost and the final charge on a successful generation', function () {
        $generation = Generation::factory()->succeeded()->create();

        expect($generation->quoted_nanos)->toBeInstanceOf(Money::class)
            ->and($generation->charged_nanos->toUsdString())->toBe('0.068200000')
            ->and($generation->provider_cost_nanos->toUsdString())->toBe('0.054560000');
    });
});

describe('the status rank stays in step with the status', function () {
    it('sets the rank whenever a status is written', function (GenerationStatus $status, int $expectedRank) {
        $generation = Generation::factory()->create(['status' => GenerationStatus::Creating]);

        $generation->status = $status;

        if ($status === GenerationStatus::Succeeded) {
            $generation->provider_cost_nanos = Money::fromUsd('0.01');
            $generation->charged_nanos = Money::fromUsd('0.0125');
        }

        $generation->save();

        expect($generation->fresh()->status_rank)->toBe($expectedRank);
    })->with([
        'creating' => [GenerationStatus::Creating, 0],
        'queued' => [GenerationStatus::Queued, 1],
        'processing' => [GenerationStatus::Processing, 2],
        'succeeded' => [GenerationStatus::Succeeded, 3],
        'failed' => [GenerationStatus::Failed, 3],
    ]);
});

describe('money crossing the database boundary', function () {
    it('returns Money from every monetary column', function () {
        $user = User::factory()->create();

        // balance_nanos is intentionally absent from the model's fillable
        // attributes. A balance must never be settable from request input, so
        // it is assigned explicitly by the code entitled to change it.
        $user->balance_nanos = Money::fromUsd('5.00');
        $user->save();

        expect($user->fresh()->balance_nanos)->toBeInstanceOf(Money::class)
            ->and($user->fresh()->balance_nanos->toUsdString())->toBe('5.000000000');
    });

    it('refuses to write a monetary column from a bare integer', function () {
        $user = User::factory()->create();

        expect(fn () => $user->balance_nanos = 1_000)
            ->toThrow(InvalidArgumentException::class);
    });

    it('derives a balance from the ledger', function () {
        $user = User::factory()->create();

        LedgerEntry::factory()->topup('10.00')->create(['user_id' => $user->id]);
        LedgerEntry::factory()->create([
            'user_id' => $user->id,
            'generation_id' => Generation::factory()->succeeded()->create(['user_id' => $user->id])->id,
            'amount_nanos' => Money::fromUsd('-0.068200000'),
        ]);

        expect($user->ledgerBalance()->toUsdString())->toBe('9.931800000');
    });
});
