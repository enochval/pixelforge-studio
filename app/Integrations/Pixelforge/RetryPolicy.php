<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge;

use App\Integrations\Pixelforge\Exceptions\ProviderException;
use Random\Randomizer;
use Throwable;

/**
 * Decides whether a failed request is worth repeating, and how long to wait.
 *
 * Separated from the client so the decision can be tested on its own, with a
 * seeded source of randomness, rather than inferred from the timing of a live
 * HTTP call.
 */
final class RetryPolicy
{
    public function __construct(
        private readonly int $maxAttempts = 5,
        private readonly int $baseDelayMilliseconds = 250,
        private readonly int $maxDelayMilliseconds = 30_000,
        private readonly Randomizer $randomizer = new Randomizer,
    ) {}

    public function maxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function shouldRetry(Throwable $failure, int $attempt): bool
    {
        if ($attempt >= $this->maxAttempts) {
            return false;
        }

        return $failure instanceof ProviderException && $failure->isRetryable();
    }

    /**
     * How long to wait before attempt number $attempt, counting from one.
     *
     * When the provider says how long to wait, that instruction is used: it
     * reflects the state of a limiter this application cannot see.
     *
     * Otherwise the delay is a random value between zero and an exponentially
     * growing ceiling — full jitter rather than a fixed schedule. If a hundred
     * queued jobs are rejected by one overloaded provider, a fixed backoff
     * returns all hundred at the same instant and reproduces the overload.
     * Spreading them across the window is the point; the ceiling only bounds
     * how far they spread.
     */
    public function delayMilliseconds(int $attempt, ?int $retryAfterSeconds = null): int
    {
        if ($retryAfterSeconds !== null) {
            return min($retryAfterSeconds * 1_000, $this->maxDelayMilliseconds);
        }

        $ceiling = min(
            $this->baseDelayMilliseconds * (2 ** max(0, $attempt - 1)),
            $this->maxDelayMilliseconds,
        );

        return $this->randomizer->getInt(0, (int) $ceiling);
    }
}
