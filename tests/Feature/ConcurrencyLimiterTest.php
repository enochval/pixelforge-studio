<?php

declare(strict_types=1);

use App\Integrations\Pixelforge\ConcurrencyLimiter;
use App\Integrations\Pixelforge\Exceptions\ConcurrencyLimited;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Str;

/**
 * Runs against the real Redis in the development stack rather than a mock.
 * The thing under test is the coordination between separate processes, and a
 * mock of the limiter would assert only that the code calls the code.
 */
function limiter(int $maxConcurrent, int $blockForSeconds = 1): ConcurrencyLimiter
{
    return new ConcurrencyLimiter(
        redis: app(RedisFactory::class),
        maxConcurrent: $maxConcurrent,
        blockForSeconds: $blockForSeconds,
        name: 'pixelforge-test-'.Str::random(12),
    );
}

it('runs work while a slot is free', function () {
    expect(limiter(maxConcurrent: 2)->run(fn () => 'done'))->toBe('done');
});

it('releases the slot once the work finishes', function () {
    $limiter = limiter(maxConcurrent: 1);

    // Three sequential calls through a limiter of one only succeed if each
    // releases its slot on the way out.
    expect($limiter->run(fn () => 1))->toBe(1)
        ->and($limiter->run(fn () => 2))->toBe(2)
        ->and($limiter->run(fn () => 3))->toBe(3);
});

it('releases the slot even when the work throws', function () {
    $limiter = limiter(maxConcurrent: 1);

    expect(fn () => $limiter->run(fn () => throw new RuntimeException('failed')))
        ->toThrow(RuntimeException::class);

    // If a failure leaked a slot, this would block and then be refused.
    expect($limiter->run(fn () => 'still works'))->toBe('still works');
});

it('refuses rather than waits when every slot is taken', function () {
    $limiter = limiter(maxConcurrent: 1, blockForSeconds: 1);

    $refused = false;

    $limiter->run(function () use ($limiter, &$refused): void {
        // A nested call stands in for a second worker holding the only slot.
        try {
            $limiter->run(fn () => 'should not run');
        } catch (ConcurrencyLimited) {
            $refused = true;
        }
    });

    // Telling the caller is the point. A worker that sleeps here is held
    // hostage by a condition only another worker can clear, and under load
    // every worker ends up asleep waiting on every other.
    expect($refused)->toBeTrue();
});

it('allows as many simultaneous holders as the limit permits', function () {
    $limiter = limiter(maxConcurrent: 3);
    $depth = 0;

    $limiter->run(function () use ($limiter, &$depth): void {
        $depth++;
        $limiter->run(function () use ($limiter, &$depth): void {
            $depth++;
            $limiter->run(function () use (&$depth): void {
                $depth++;
            });
        });
    });

    expect($depth)->toBe(3);
});
