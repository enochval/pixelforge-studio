<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge;

use App\Integrations\Pixelforge\Exceptions\ConcurrencyLimited;
use Closure;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Contracts\Redis\LimiterTimeoutException;

/**
 * Caps how many requests to the provider are in flight at once.
 *
 * A rate limit and a concurrency limit are different constraints and need
 * different tools. Backoff addresses requests per window; it does nothing
 * about the number running simultaneously, because the thing that frees a
 * slot is another request finishing rather than time passing.
 *
 * Slots are held in Redis because workers are separate processes, often on
 * separate machines. A counter local to one worker would cap that worker and
 * nothing else.
 *
 * When no slot can be acquired the caller is told so rather than made to wait.
 * Sleeping inside a worker holds a process hostage for a condition only
 * another worker can clear, and under load that ends with every worker asleep
 * waiting on every other.
 */
final class ConcurrencyLimiter
{
    public function __construct(
        private readonly RedisFactory $redis,
        private readonly int $maxConcurrent,
        private readonly int $blockForSeconds = 3,
        private readonly string $name = 'pixelforge',
    ) {}

    /**
     * Run $work while holding a slot.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $work
     * @return TReturn
     *
     * @throws ConcurrencyLimited when no slot became available in time
     */
    public function run(Closure $work): mixed
    {
        try {
            return $this->redis->connection()
                ->funnel($this->name)
                ->limit($this->maxConcurrent)
                ->block($this->blockForSeconds)
                ->then($work, function (): never {
                    throw new ConcurrencyLimited(
                        "No provider slot available within {$this->blockForSeconds}s.",
                    );
                });
        } catch (LimiterTimeoutException $e) {
            throw new ConcurrencyLimited('Timed out waiting for a provider slot.', previous: $e);
        }
    }
}
