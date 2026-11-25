<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base for every failure the provider can produce.
 *
 * Failures are typed by category rather than reported as one exception with a
 * status code, because the caller's decision differs per category: a rate
 * limit is waited out, an overload is backed off with jitter, a concurrency
 * limit releases the job rather than holding a worker, and a malformed request
 * is never retried at all. Branching on a status code at each call site would
 * put that decision in several places and let them drift.
 */
abstract class ProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Whether repeating the request could succeed.
     */
    abstract public function isRetryable(): bool;
}
