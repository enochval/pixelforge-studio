<?php

declare(strict_types=1);

use App\Domain\LedgerEntryKind;
use App\Integrations\Pixelforge\Contracts\ImageProvider;
use App\Integrations\Pixelforge\FakeImageProvider;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->app->instance(ImageProvider::class, new FakeImageProvider);

    // The policy reads a configured list rather than a role column, so a test
    // names its operator the same way production does.
    config(['pixelforge.operators' => ['boss@pixelforge.test']]);

    $this->operator = fundedUser('5.00', ['email' => 'boss@pixelforge.test']);
    $this->customer = fundedUser('5.00');
});

describe('who may see the report', function () {
    it('is not reachable without signing in', function () {
        $this->get('/operator')->assertRedirect('/login');
    });

    it('refuses a signed-in user who is not an operator', function () {
        // The report shows every user's spend and the margin taken on it, so
        // guessing the URL must not be enough.
        actingAs($this->customer)->get('/operator')->assertForbidden();
    });

    it('allows a configured operator', function () {
        actingAs($this->operator)
            ->get('/operator')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Operator/Index'));
    });

    it('offers the link only to an operator', function () {
        actingAs($this->operator)
            ->get('/generations')
            ->assertInertia(fn ($page) => $page->where('isOperator', true));

        actingAs($this->customer)
            ->get('/generations')
            ->assertInertia(fn ($page) => $page->where('isOperator', false));
    });
});

describe('what the report shows', function () {
    it('sends the figures the page renders', function () {
        Generation::factory()->succeeded()->create(['user_id' => $this->customer->id]);

        actingAs($this->operator)
            ->get('/operator')
            ->assertInertia(fn ($page) => $page
                ->has('report.by_day')
                ->has('report.by_user')
                ->has('report.totals')
                ->has('report.reconciliation')
                ->where('report.totals.generations', 1));
    });

    it('accepts a range and bounds it', function () {
        // 92 days is the provider's own limit on a usage query, so a longer
        // range could not be reconciled even if it were asked for.
        actingAs($this->operator)
            ->get('/operator?days=500')
            ->assertInertia(fn ($page) => $page->where('days', 92));

        actingAs($this->operator)
            ->get('/operator?days=0')
            ->assertInertia(fn ($page) => $page->where('days', 1));
    });
});

describe('buying credits', function () {
    it('adds credit through the ledger', function () {
        actingAs($this->customer)
            ->from('/generations')
            ->post('/credits', ['amount' => '5.00'])
            ->assertRedirect('/generations')
            ->assertSessionHas('status');

        // Through the ledger, not by setting the balance: a top-up is a
        // movement of money and has to be explicable like any other.
        $topUps = LedgerEntry::query()
            ->where('user_id', $this->customer->id)
            ->where('kind', LedgerEntryKind::Topup)
            ->get();

        expect($topUps)->toHaveCount(2)
            ->and($this->customer->fresh()->balance_nanos->toUsdString())->toBe('10.000000000');
    });

    it('keeps the balance cache equal to the ledger', function () {
        actingAs($this->customer)->post('/credits', ['amount' => '20.00']);

        $user = $this->customer->fresh();

        expect($user->balance_nanos->nanos)->toBe($user->ledgerBalance()->nanos);

        $this->artisan('pixelforge:verify-balances')->assertSuccessful();
    });

    it('refuses an amount that is not on offer', function () {
        // The amount is validated against a fixed server-side list. An amount a
        // client can choose is an amount a client can forge, and this one adds
        // money to a balance.
        actingAs($this->customer)
            ->post('/credits', ['amount' => '1000000.00'])
            ->assertSessionHasErrors('amount');

        expect($this->customer->fresh()->balance_nanos->toUsdString())->toBe('5.000000000');
    });

    it('refuses a missing amount', function () {
        actingAs($this->customer)
            ->post('/credits', [])
            ->assertSessionHasErrors('amount');
    });

    it('is not reachable without signing in', function () {
        $this->post('/credits', ['amount' => '5.00'])->assertRedirect('/login');

        expect(LedgerEntry::where('kind', LedgerEntryKind::Topup)->count())->toBe(2);
    });

    it('credits the buyer and nobody else', function () {
        $before = $this->operator->fresh()->balance_nanos->nanos;

        actingAs($this->customer)->post('/credits', ['amount' => '1.00']);

        expect($this->operator->fresh()->balance_nanos->nanos)->toBe($before);
    });

    it('lets a user who ran out of credit generate again', function () {
        // The point of the button. Without it, a balance that runs out is a
        // dead end that only an artisan command can clear.
        $broke = User::factory()->create();

        actingAs($broke)
            ->post('/generations', ['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low'])
            ->assertSessionHasErrors('prompt');

        actingAs($broke)->post('/credits', ['amount' => '5.00']);

        actingAs($broke)
            ->post('/generations', ['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low'])
            ->assertSessionHasNoErrors();

        expect(Generation::where('user_id', $broke->id)->count())->toBe(1);
    });
});

describe('the policy itself', function () {
    it('matches an operator exactly, not loosely', function () {
        config(['pixelforge.operators' => ['boss@pixelforge.test']]);

        $lookalike = User::factory()->create(['email' => 'boss@pixelforge.test.evil.com']);

        expect($this->operator->can('view-operator-report'))->toBeTrue()
            ->and($lookalike->can('view-operator-report'))->toBeFalse();
    });

    it('allows nobody when no operator is configured', function () {
        config(['pixelforge.operators' => []]);

        expect($this->operator->can('view-operator-report'))->toBeFalse();
    });
});
