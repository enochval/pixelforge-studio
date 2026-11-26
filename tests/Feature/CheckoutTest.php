<?php

declare(strict_types=1);

use App\Domain\LedgerEntryKind;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Support\MockCardPayment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/**
 * Buying credit with a simulated card.
 *
 * The flow exists so that a payment can fail. A single button that always
 * succeeds never exercises what happens when one does not — and what happens
 * matters, because the thing on the other side of it adds money to a balance.
 */
beforeEach(function () {
    $this->user = fundedUser('1.00');
});

/** Opens the page and returns the single-use intent it was issued. */
function freshIntent(User $user): string
{
    return actingAs($user)->get('/checkout')->viewData('page')['props']['intent'];
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function cardPayload(string $intent, array $overrides = []): array
{
    return array_replace([
        'intent' => $intent,
        'amount' => '5.00',
        'card_number' => MockCardPayment::SUCCESS_CARD,
        'card_expiry' => '12 / 30',
        'card_cvc' => '123',
        'card_name' => 'Demo Customer',
    ], $overrides);
}

describe('the checkout page', function () {
    it('issues a single-use intent and publishes the test cards', function () {
        actingAs($this->user)
            ->get('/checkout')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Checkout/Index')
                ->has('intent')
                ->has('packs', 3)
                ->has('testCards', 4)
                ->where('balance', 1_000_000_000));
    });

    it('is not reachable without signing in', function () {
        $this->get('/checkout')->assertRedirect('/login');
    });
});

describe('a successful payment', function () {
    it('adds credit through the ledger and says what was authorised', function () {
        $intent = freshIntent($this->user);

        actingAs($this->user)
            ->post('/checkout', cardPayload($intent))
            ->assertRedirect('/generations')
            ->assertSessionHas('status');

        // Through the ledger, not by setting a balance: bought credit is a
        // movement of money and has to be explicable like any other.
        $topUps = LedgerEntry::query()
            ->where('user_id', $this->user->id)
            ->where('kind', LedgerEntryKind::Topup)
            ->count();

        expect($topUps)->toBe(2)
            ->and($this->user->fresh()->balance_nanos->toUsdString())->toBe('6.000000000');
    });

    it('keeps the cached balance equal to the ledger', function () {
        actingAs($this->user)->post('/checkout', cardPayload(freshIntent($this->user)));

        $user = $this->user->fresh();

        expect($user->balance_nanos->nanos)->toBe($user->ledgerBalance()->nanos);

        $this->artisan('pixelforge:verify-balances')->assertSuccessful();
    });

    it('lets a customer who ran out of credit generate again', function () {
        // The point of the whole flow. Without it, a balance that runs out is a
        // dead end only an artisan command can clear.
        $broke = User::factory()->create();

        actingAs($broke)
            ->post('/generations', ['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low'])
            ->assertSessionHasErrors('prompt');

        actingAs($broke)->post('/checkout', cardPayload(freshIntent($broke)));

        actingAs($broke)
            ->post('/generations', ['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low'])
            ->assertSessionHasNoErrors();

        expect(Generation::where('user_id', $broke->id)->count())->toBe(1);
    });
});

describe('a declined payment', function () {
    it('credits nothing, for each way a card can be refused', function (string $card, string $expected) {
        $intent = freshIntent($this->user);

        actingAs($this->user)
            ->post('/checkout', cardPayload($intent, ['card_number' => $card]))
            ->assertSessionHasErrors('card_number');

        // Nothing moved. A declined payment that half-credited an account
        // would be worse than one that failed outright.
        expect($this->user->fresh()->balance_nanos->toUsdString())->toBe('1.000000000')
            ->and(session('errors')->first('card_number'))->toContain($expected);
    })->with([
        'declined' => [MockCardPayment::DECLINED_CARD, 'declined'],
        'insufficient funds' => [MockCardPayment::INSUFFICIENT_FUNDS_CARD, 'insufficient funds'],
        'expired' => [MockCardPayment::EXPIRED_CARD, 'expired'],
    ]);

    it('spends the intent even when the card is refused', function () {
        // Deliberate. The submission genuinely happened and is finished with,
        // so a retry starts from a fresh page rather than replaying one whose
        // outcome is already known.
        $intent = freshIntent($this->user);

        actingAs($this->user)->post('/checkout', cardPayload($intent, [
            'card_number' => MockCardPayment::DECLINED_CARD,
        ]));

        actingAs($this->user)
            ->post('/checkout', cardPayload($intent))
            ->assertSessionHasErrors('intent');

        expect($this->user->fresh()->balance_nanos->toUsdString())->toBe('1.000000000');
    });
});

describe('submitting twice', function () {
    it('credits once when the same intent is submitted again', function () {
        // A double click, an impatient refresh, a retried request. Without the
        // intent, the second submission would add the money a second time.
        $intent = freshIntent($this->user);
        $payload = cardPayload($intent);

        actingAs($this->user)->post('/checkout', $payload)->assertRedirect('/generations');
        actingAs($this->user)->post('/checkout', $payload)->assertSessionHasErrors('intent');

        expect($this->user->fresh()->balance_nanos->toUsdString())->toBe('6.000000000')
            ->and(LedgerEntry::where('user_id', $this->user->id)
                ->where('kind', LedgerEntryKind::Topup)
                ->count())->toBe(2);
    });

    it('refuses an intent that was never issued', function () {
        actingAs($this->user)
            ->post('/checkout', cardPayload(Str::uuid()->toString()))
            ->assertSessionHasErrors('intent');

        expect($this->user->fresh()->balance_nanos->toUsdString())->toBe('1.000000000');
    });

    it('refuses another account\'s intent', function () {
        // Intents are scoped to the user who was issued one, so holding
        // somebody else's is not holding a payment.
        $other = fundedUser('1.00');
        $intent = freshIntent($other);

        actingAs($this->user)
            ->post('/checkout', cardPayload($intent))
            ->assertSessionHasErrors('intent');

        expect($this->user->fresh()->balance_nanos->toUsdString())->toBe('1.000000000');
    });

    it('refuses an intent that has expired', function () {
        $intent = freshIntent($this->user);

        Cache::flush();

        actingAs($this->user)
            ->post('/checkout', cardPayload($intent))
            ->assertSessionHasErrors('intent');
    });
});

describe('what the form accepts', function () {
    it('refuses an amount that is not on offer', function () {
        // The amount is validated against a fixed server-side list. An amount a
        // client can name is an amount a client can forge, and this one adds
        // money to a balance.
        actingAs($this->user)
            ->post('/checkout', cardPayload(freshIntent($this->user), ['amount' => '1000000.00']))
            ->assertSessionHasErrors('amount');

        expect($this->user->fresh()->balance_nanos->toUsdString())->toBe('1.000000000');
    });

    it('does not spend the intent on a validation failure', function () {
        // Validation runs before the intent is claimed, so a mistyped expiry
        // does not cost the payer their payment.
        $intent = freshIntent($this->user);

        actingAs($this->user)
            ->post('/checkout', cardPayload($intent, ['card_expiry' => 'nonsense']))
            ->assertSessionHasErrors('card_expiry');

        actingAs($this->user)
            ->post('/checkout', cardPayload($intent))
            ->assertSessionHasNoErrors();

        expect($this->user->fresh()->balance_nanos->toUsdString())->toBe('6.000000000');
    });

    it('requires every card field', function (string $field) {
        actingAs($this->user)
            ->post('/checkout', cardPayload(freshIntent($this->user), [$field => '']))
            ->assertSessionHasErrors($field);
    })->with(['card_number', 'card_expiry', 'card_cvc', 'card_name']);
});
