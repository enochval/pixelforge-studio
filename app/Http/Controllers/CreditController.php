<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Money;
use App\Support\Ledger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Buying credit — a fake purchase. No payment is taken.
 *
 * The amount is validated against a fixed server-side list rather than accepted
 * from the request. An amount a client can choose is an amount a client can
 * forge, and this one adds money to a balance.
 *
 * Even as a stand-in for a payment flow, the entry goes through the ledger like
 * every other movement, so the invariant that a cached balance equals the sum of
 * its entries holds for purchased credit too.
 */
final class CreditController extends Controller
{
    public function store(Request $request, Ledger $ledger): RedirectResponse
    {
        /** @var array<int, string> $packs */
        $packs = config('pixelforge.credit_packs');

        $validated = $request->validate([
            'amount' => ['required', 'string', Rule::in($packs)],
        ]);

        $ledger->topUp($request->user(), Money::fromUsd($validated['amount']));

        return back()->with('status', 'Added $'.$validated['amount'].' of credit.');
    }
}
