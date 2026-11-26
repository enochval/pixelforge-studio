<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Money;
use App\Support\Ledger;
use App\Support\MockCardPayment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Buying credit with a simulated card.
 *
 * Two steps on one page: choose an amount, enter card details, see the result.
 * No money moves anywhere real and the page says so.
 *
 * The part worth reading is the intent. A form that posts an amount and adds
 * credit can be submitted twice — a double click, an impatient refresh, a
 * retried request — and the second submission would add the money again. So
 * the page is issued a single-use intent, and claiming it is what authorises
 * the credit.
 *
 * Claimed with Cache::pull, which reads and deletes in one operation. A read
 * followed by a delete would leave a window in which two requests both read a
 * valid intent, and that window is the whole problem — the same reasoning as
 * the conditional updates used everywhere money moves in this application.
 */
final class CheckoutController extends Controller
{
    /**
     * How long an unused intent stays valid. Long enough to type card details,
     * short enough that an abandoned page cannot be revived days later.
     */
    private const INTENT_TTL_SECONDS = 1_800;

    public function create(Request $request): Response
    {
        $intent = Str::uuid()->toString();

        Cache::put($this->intentKey($request, $intent), true, self::INTENT_TTL_SECONDS);

        return Inertia::render('Checkout/Index', [
            'intent' => $intent,
            'packs' => $this->packs(),
            'balance' => $request->user()->balance_nanos->nanos,

            // Published so the simulation is testable by hand rather than by
            // guesswork. A demonstration nobody can make fail is not showing
            // the failure handling.
            'testCards' => [
                ['number' => MockCardPayment::SUCCESS_CARD, 'outcome' => 'Succeeds'],
                ['number' => MockCardPayment::DECLINED_CARD, 'outcome' => 'Declined'],
                ['number' => MockCardPayment::INSUFFICIENT_FUNDS_CARD, 'outcome' => 'Insufficient funds'],
                ['number' => MockCardPayment::EXPIRED_CARD, 'outcome' => 'Expired card'],
            ],
        ]);
    }

    public function store(Request $request, MockCardPayment $payments, Ledger $ledger): RedirectResponse
    {
        $validated = $request->validate([
            'intent' => ['required', 'uuid'],

            // Amounts are a fixed server-side list. An amount a client can
            // name is an amount a client can forge, and this one adds money.
            'amount' => ['required', 'string', Rule::in(array_column($this->packs(), 'amount'))],

            'card_number' => ['required', 'string', 'min:12', 'max:24'],
            'card_expiry' => ['required', 'string', 'regex:/^\d{2}\s*\/\s*\d{2}$/'],
            'card_cvc' => ['required', 'string', 'digits_between:3,4'],
            'card_name' => ['required', 'string', 'max:120'],
        ], [
            'card_expiry.regex' => 'Use MM / YY.',
        ]);

        // Claimed, not checked. Reading and then deleting would leave a window
        // in which two submissions both find a valid intent.
        if (Cache::pull($this->intentKey($request, $validated['intent'])) === null) {
            throw ValidationException::withMessages([
                'intent' => 'This payment has already been completed. Start a new one if you want to add more credit.',
            ]);
        }

        $amount = Money::fromUsd($validated['amount']);
        $result = $payments->charge($validated['card_number'], $amount);

        if (! $result->authorised) {
            // The intent is already spent, so a retry needs a fresh page. That
            // is the honest behaviour: the payer is being sent back to a form
            // whose previous submission is genuinely finished with.
            throw ValidationException::withMessages([
                'card_number' => (string) $result->message,
            ]);
        }

        // Credited from the amount the payment authorised, not the amount the
        // form asked for. They are the same here because the simulation
        // authorises what it is given, but reading it back from the result is
        // what a real integration has to do — a provider can authorise a
        // different amount, and crediting the requested one would then be
        // crediting money nobody took.
        $authorised = $result->amount ?? $amount;

        $ledger->topUp($request->user(), $authorised);

        return redirect()
            ->route('generations.index')
            ->with('status', 'Payment '.$result->reference.' authorised. '
                .$authorised->toUsdString(2).' of credit added.');
    }

    /**
     * Scoped to the user, so one account cannot spend another's intent.
     */
    private function intentKey(Request $request, string $intent): string
    {
        return "checkout:{$request->user()->id}:{$intent}";
    }

    /**
     * The amounts on offer, with the middle one marked.
     *
     * Marking the middle rather than a fixed position, so adding or removing an
     * amount in configuration does not silently move the label onto the
     * cheapest or the dearest option.
     *
     * @return array<int, array{amount: string, label: string, note: string|null}>
     */
    private function packs(): array
    {
        /** @var array<int, string> $amounts */
        $amounts = array_values(config('pixelforge.credit_packs'));

        $middle = intdiv(count($amounts) - 1, 2);

        return array_map(static fn (string $amount, int $index): array => [
            'amount' => $amount,
            'label' => '$'.number_format((float) $amount, 2),
            'note' => count($amounts) > 2 && $index === $middle ? 'Most popular' : null,
        ], $amounts, array_keys($amounts));
    }
}
