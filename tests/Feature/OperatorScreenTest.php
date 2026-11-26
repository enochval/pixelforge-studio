<?php

declare(strict_types=1);

use App\Integrations\Pixelforge\Contracts\ImageProvider;
use App\Integrations\Pixelforge\FakeImageProvider;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/**
 * Two applications, each closed to the other.
 *
 * An operator reconciles spend and never generates. A customer generates and
 * never sees another account's figures. Neither is a subset of the other, so
 * both directions are asserted rather than one being inferred from the other.
 */
beforeEach(function () {
    $this->app->instance(ImageProvider::class, new FakeImageProvider);

    $this->operator = User::factory()->create(['is_operator' => true]);
    $this->customer = fundedUser('5.00');
});

describe('the operator console', function () {
    it('is not reachable without signing in', function () {
        $this->get('/operator')->assertRedirect('/login');
    });

    it('refuses a customer', function () {
        // It shows every user's spend and the margin taken on it, so guessing
        // the URL must not be enough.
        actingAs($this->customer)->get('/operator')->assertForbidden();
    });

    it('allows an operator', function () {
        actingAs($this->operator)
            ->get('/operator')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Operator/Index'));
    });

    it('sends the figures the console renders', function () {
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

    it('accepts a range and bounds it to what the provider can reconcile', function () {
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

describe('the customer application', function () {
    it('is closed to an operator', function () {
        // An account that reads every user's margin should not also be spending
        // on the same balance, or a figure in the console would be partly the
        // operator's own activity.
        actingAs($this->operator)->get('/generations')->assertRedirect('/operator');
        actingAs($this->operator)->get('/checkout')->assertRedirect('/operator');
    });

    it('refuses an operator who posts to it directly', function () {
        // The redirect above is a convenience for a browser. The rule is the
        // ability, and it has to hold for a request that skips the page.
        actingAs($this->operator)
            ->post('/generations', ['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low'])
            ->assertRedirect('/operator');

        actingAs($this->operator)
            ->post('/checkout', ['intent' => Str::uuid()->toString(), 'amount' => '5.00'])
            ->assertRedirect('/operator');

        expect(Generation::count())->toBe(0);
    });

    it('is open to a customer', function () {
        actingAs($this->customer)->get('/generations')->assertOk();
        actingAs($this->customer)->get('/checkout')->assertOk();
    });
});

describe('where signing in lands', function () {
    it('sends a customer to the studio', function () {
        actingAs($this->customer)->get('/dashboard')->assertRedirect('/generations');
    });

    it('sends an operator to the console', function () {
        actingAs($this->operator)->get('/dashboard')->assertRedirect('/operator');
    });
});

describe('the abilities themselves', function () {
    it('separates the two roles in both directions', function () {
        expect($this->operator->can('view-operator-report'))->toBeTrue()
            ->and($this->operator->can('generate-images'))->toBeFalse()
            ->and($this->customer->can('view-operator-report'))->toBeFalse()
            ->and($this->customer->can('generate-images'))->toBeTrue();
    });

    it('cannot be granted by mass assignment', function () {
        // Promotion must not be something a request can ask for.
        $user = User::factory()->create();

        $user->fill(['is_operator' => true]);

        expect($user->is_operator)->toBeFalse();
    });

    it('defaults a new account to customer', function () {
        expect(User::factory()->create()->isOperator())->toBeFalse();
    });
});
