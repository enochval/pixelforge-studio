<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Money;

/**
 * A simulated card payment. No money moves anywhere real.
 *
 * It exists because "add credit" as a single button press is not a flow anyone
 * can reason about: there is no point at which a payment can fail, so nothing
 * downstream ever has to cope with one failing. Simulating the failures is the
 * only way the rest of the code gets exercised.
 *
 * The outcome is decided by the card number, in the manner real providers use
 * for their test cards. That makes a declined payment reproducible rather than
 * random — a demonstration that fails on a coin toss is worse than one that
 * always succeeds.
 *
 * Nothing here touches the ledger. A payment either authorises or it does not;
 * crediting an account is a separate decision made by the caller, which is why
 * a declined payment cannot half-credit anyone.
 */
final readonly class MockCardPayment
{
    /**
     * Card numbers with a fixed meaning, in the order they are checked.
     *
     * Chosen to match the numbers real providers publish, so anyone who has
     * integrated a payment provider already knows what they do.
     */
    public const SUCCESS_CARD = '4242424242424242';

    public const DECLINED_CARD = '4000000000000002';

    public const INSUFFICIENT_FUNDS_CARD = '4000000000009995';

    public const EXPIRED_CARD = '4000000000000069';

    public function charge(string $cardNumber, Money $amount): PaymentResult
    {
        $digits = preg_replace('/\D/', '', $cardNumber) ?? '';

        return match ($digits) {
            self::DECLINED_CARD => PaymentResult::declined(
                'card_declined',
                'Your card was declined. Try a different card.',
            ),
            self::INSUFFICIENT_FUNDS_CARD => PaymentResult::declined(
                'insufficient_funds',
                'Your card has insufficient funds.',
            ),
            self::EXPIRED_CARD => PaymentResult::declined(
                'expired_card',
                'Your card has expired.',
            ),

            // Everything else authorises. A simulation that refused unfamiliar
            // numbers would make the common path the hard one.
            default => PaymentResult::authorised(
                // A reference in the shape a provider would return, recorded
                // against the resulting credit so a balance can be traced back
                // to the payment that produced it.
                'pay_'.strtoupper(bin2hex(random_bytes(10))),
                $amount,
            ),
        };
    }
}
