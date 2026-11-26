<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The user's balance cannot cover the quoted ceiling for a generation.
 *
 * Carries a message written for the user rather than for a log, because this is
 * the one failure in the submission path that is entirely within their power to
 * resolve — and the amount they would need is the useful part of saying so.
 */
final class InsufficientCredit extends RuntimeException {}
