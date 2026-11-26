<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Money;

/**
 * What a simulated payment decided.
 *
 * Two states and no third: authorised with a reference and an amount, or
 * declined with a reason the payer can act on. There is deliberately no
 * "pending" — a real integration would need one, and inventing it here without
 * anything to resolve it would be a state nothing could ever leave.
 */
final readonly class PaymentResult
{
    private function __construct(
        public bool $authorised,
        public ?string $reference = null,
        public ?Money $amount = null,
        public ?string $code = null,
        public ?string $message = null,
    ) {}

    public static function authorised(string $reference, Money $amount): self
    {
        return new self(authorised: true, reference: $reference, amount: $amount);
    }

    public static function declined(string $code, string $message): self
    {
        return new self(authorised: false, code: $code, message: $message);
    }
}
