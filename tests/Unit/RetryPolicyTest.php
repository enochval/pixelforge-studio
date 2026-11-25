<?php

declare(strict_types=1);

use App\Integrations\Pixelforge\Exceptions\AuthenticationFailed;
use App\Integrations\Pixelforge\Exceptions\ConcurrencyLimited;
use App\Integrations\Pixelforge\Exceptions\InvalidRequest;
use App\Integrations\Pixelforge\Exceptions\ProviderOverloaded;
use App\Integrations\Pixelforge\Exceptions\ProviderUnavailable;
use App\Integrations\Pixelforge\Exceptions\RateLimited;
use App\Integrations\Pixelforge\RetryPolicy;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Seeded so the jitter is reproducible. Timing-dependent assertions about a
 * random delay are the kind of test that passes for months and then fails on
 * a Tuesday.
 */
function seededPolicy(int $maxAttempts = 5, int $seed = 1): RetryPolicy
{
    return new RetryPolicy(
        maxAttempts: $maxAttempts,
        randomizer: new Randomizer(new Mt19937($seed)),
    );
}

it('retries the failures that could succeed on a second attempt', function (Throwable $failure, bool $expected) {
    expect(seededPolicy()->shouldRetry($failure, attempt: 1))->toBe($expected);
})->with([
    'rate limited' => [new RateLimited('slow down'), true],
    'concurrency limited' => [new ConcurrencyLimited('no slot'), true],
    'overloaded' => [new ProviderOverloaded('busy'), true],
    'connection failed' => [new ProviderUnavailable('timeout'), true],
    'malformed request' => [new InvalidRequest('bad prompt'), false],
    'bad credentials' => [new AuthenticationFailed('unauthorised'), false],
    'something unrelated' => [new RuntimeException('unexpected'), false],
]);

it('stops once the attempt limit is reached', function () {
    $policy = seededPolicy(maxAttempts: 3);
    $failure = new ProviderOverloaded('busy');

    expect($policy->shouldRetry($failure, attempt: 1))->toBeTrue()
        ->and($policy->shouldRetry($failure, attempt: 2))->toBeTrue()
        ->and($policy->shouldRetry($failure, attempt: 3))->toBeFalse();
});

it('waits as long as the provider asks when it says so', function () {
    // The provider can see the state of its own limiter. This application
    // cannot, so its instruction is preferred over any calculated backoff.
    expect(seededPolicy()->delayMilliseconds(attempt: 1, retryAfterSeconds: 7))->toBe(7_000);
});

it('caps a long Retry-After rather than sleeping indefinitely', function () {
    expect(seededPolicy()->delayMilliseconds(attempt: 1, retryAfterSeconds: 3_600))->toBe(30_000);
});

it('spreads retries across an exponentially growing window', function () {
    $policy = seededPolicy();

    // Full jitter: each delay is somewhere between zero and a ceiling that
    // doubles per attempt. The ceiling is asserted, not the value, because
    // the value is deliberately random.
    foreach ([1 => 250, 2 => 500, 3 => 1_000, 4 => 2_000] as $attempt => $ceiling) {
        $delays = array_map(
            fn () => $policy->delayMilliseconds($attempt),
            range(1, 50),
        );

        expect(max($delays))->toBeLessThanOrEqual($ceiling)
            ->and(min($delays))->toBeGreaterThanOrEqual(0);
    }
});

it('does not return the same delay to every caller', function () {
    $policy = seededPolicy();

    // The point of jitter: a hundred workers rejected by one overloaded
    // provider must not all return at the same instant and recreate the
    // overload. A fixed backoff would make these identical.
    $delays = array_map(fn () => $policy->delayMilliseconds(attempt: 4), range(1, 20));

    expect(count(array_unique($delays)))->toBeGreaterThan(1);
});

it('never exceeds the maximum delay however many attempts are made', function () {
    $policy = seededPolicy(maxAttempts: 20);

    foreach (range(1, 20) as $attempt) {
        expect($policy->delayMilliseconds($attempt))->toBeLessThanOrEqual(30_000);
    }
});
