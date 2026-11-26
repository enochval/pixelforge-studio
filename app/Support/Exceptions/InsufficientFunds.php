<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use RuntimeException;

/**
 * Thrown inside a hold to roll back a reservation the balance cannot cover.
 *
 * Internal to Ledger::hold(), which catches it and answers false. It exists
 * because the ledger entry is written before the balance moves, so refusing the
 * hold has to undo that write — and rolling back the transaction is the only
 * way to undo it without a second statement that could itself fail.
 *
 * Never reaches a caller, which is why it carries no message or context.
 */
final class InsufficientFunds extends RuntimeException {}
