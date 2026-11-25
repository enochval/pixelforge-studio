<?php

declare(strict_types=1);

use App\Domain\GenerationRequest;
use App\Domain\GenerationStatus;
use App\Integrations\Pixelforge\ConcurrencyLimiter;
use App\Integrations\Pixelforge\Data\GenerationSubmission;
use App\Integrations\Pixelforge\Exceptions\AuthenticationFailed;
use App\Integrations\Pixelforge\Exceptions\ConcurrencyLimited;
use App\Integrations\Pixelforge\Exceptions\InvalidRequest;
use App\Integrations\Pixelforge\Exceptions\ProviderOverloaded;
use App\Integrations\Pixelforge\Exceptions\ProviderUnavailable;
use App\Integrations\Pixelforge\Exceptions\RateLimited;
use App\Integrations\Pixelforge\PixelforgeClient;
use App\Integrations\Pixelforge\RetryPolicy;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Delays are configured to zero so the suite does not actually sleep through
 * its own backoff. The timing is asserted in tests/Unit/RetryPolicyTest.php,
 * where it can be checked without waiting for it.
 */
function client(int $maxAttempts = 5): PixelforgeClient
{
    return new PixelforgeClient(
        http: app(HttpFactory::class),
        retries: new RetryPolicy(
            maxAttempts: $maxAttempts,
            baseDelayMilliseconds: 0,
            maxDelayMilliseconds: 0,
            randomizer: new Randomizer(new Mt19937(1)),
        ),
        limiter: new ConcurrencyLimiter(
            redis: app(RedisFactory::class),
            maxConcurrent: 5,
            blockForSeconds: 1,
            name: 'pixelforge-test-'.Str::random(8),
        ),
        baseUrl: 'https://provider.test',
        apiKey: 'test-key',
    );
}

function submission(string $prompt = 'a cat', string $idempotencyKey = 'idem-1'): GenerationSubmission
{
    return new GenerationSubmission(
        request: new GenerationRequest($prompt, '1024x1024', 'standard'),
        idempotencyKey: $idempotencyKey,
        clientReference: 'our-uuid-1',
    );
}

describe('submitting', function () {
    it('sends the prompt, our reference and an idempotency key', function () {
        Http::fake(['*' => Http::response(['id' => 'gen_abc', 'status' => 'queued'], 202)]);

        $result = client()->submit(submission());

        expect($result->providerGenerationId)->toBe('gen_abc')
            ->and($result->status)->toBe(GenerationStatus::Queued);

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('Idempotency-Key', 'idem-1')
                && $request['prompt'] === 'a cat'
                && $request['metadata']['generation_id'] === 'our-uuid-1';
        });
    });

    it('sends the same idempotency key on every retry', function () {
        // A retried submission must not become a second generation. The key
        // travelling unchanged is what makes the repeat safe.
        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => ['message' => 'busy']], 503)
                ->push(['error' => ['message' => 'busy']], 503)
                ->push(['id' => 'gen_abc', 'status' => 'queued'], 202),
        ]);

        client()->submit(submission(idempotencyKey: 'idem-stable'));

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Idempotency-Key', 'idem-stable'));
    });
});

describe('failures worth repeating', function () {
    it('retries an overloaded provider and succeeds when it recovers', function () {
        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => ['message' => 'overloaded']], 503)
                ->push(['id' => 'gen_abc', 'status' => 'queued'], 202),
        ]);

        expect(client()->submit(submission())->providerGenerationId)->toBe('gen_abc');

        Http::assertSentCount(2);
    });

    it('gives up once attempts are exhausted and reports the category', function () {
        Http::fake(['*' => Http::response(['error' => ['message' => 'still overloaded']], 503)]);

        expect(fn () => client(maxAttempts: 3)->submit(submission()))
            ->toThrow(ProviderOverloaded::class);

        Http::assertSentCount(3);
    });

    it('reads Retry-After from a rate limit response', function () {
        Http::fake(['*' => Http::response(
            ['error' => ['message' => 'too many requests']],
            429,
            ['Retry-After' => '12'],
        )]);

        expect(fn () => client(maxAttempts: 1)->submit(submission()))
            ->toThrow(function (RateLimited $failure) {
                // Carried on the exception so the retry policy can prefer the
                // provider's instruction over a calculated backoff.
                expect($failure->retryAfterSeconds)->toBe(12);
            });
    });

    it('distinguishes a concurrency limit from a rate limit', function () {
        // Both can arrive as 429 and they are handled differently: one is
        // waited out, the other needs something else to finish first.
        Http::fake(['*' => Http::response(
            ['error' => ['code' => 'concurrency_limit_exceeded', 'message' => 'too many in flight']],
            429,
        )]);

        expect(fn () => client(maxAttempts: 1)->submit(submission()))
            ->toThrow(ConcurrencyLimited::class);
    });

    it('treats a connection failure as an unknown outcome', function () {
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        expect(fn () => client(maxAttempts: 2)->submit(submission()))
            ->toThrow(ProviderUnavailable::class);
    });
});

describe('failures not worth repeating', function () {
    it('does not retry a request the provider rejected as malformed', function () {
        Http::fake(['*' => Http::response(['error' => ['message' => 'prompt too long']], 422)]);

        expect(fn () => client()->submit(submission()))->toThrow(InvalidRequest::class);

        // Repeating a request the provider has already refused only adds load
        // and delays telling the user.
        Http::assertSentCount(1);
    });

    it('does not retry bad credentials', function () {
        Http::fake(['*' => Http::response(['error' => ['message' => 'invalid key']], 401)]);

        expect(fn () => client()->submit(submission()))->toThrow(AuthenticationFailed::class);

        Http::assertSentCount(1);
    });
});

describe('reading a generation', function () {
    it('translates a completed generation, including its token usage', function () {
        Http::fake(['*' => Http::response([
            'id' => 'gen_abc',
            'status' => 'succeeded',
            'usage' => ['input_tokens' => 200, 'output_tokens' => 1056, 'reference_images' => 1],
            'image_url' => 'https://images.test/gen_abc.png',
        ])]);

        $generation = client()->fetch('gen_abc');

        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->usage->outputTokens)->toBe(1056)
            ->and($generation->usage->referenceImages)->toBe(1)
            ->and($generation->imageUrl)->toBe('https://images.test/gen_abc.png')
            ->and($generation->succeeded())->toBeTrue();
    });

    it('translates a failed generation and its reason', function () {
        Http::fake(['*' => Http::response([
            'id' => 'gen_abc',
            'status' => 'failed',
            'failure_reason' => 'The prompt was rejected.',
        ])]);

        $generation = client()->fetch('gen_abc');

        expect($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->failureReason)->toBe('The prompt was rejected.')
            ->and($generation->usage)->toBeNull()
            ->and($generation->isTerminal())->toBeTrue();
    });

    it('maps the provider vocabulary onto this application s own', function (string $providerStatus, GenerationStatus $expected) {
        Http::fake(['*' => Http::response(['id' => 'gen_abc', 'status' => $providerStatus])]);

        expect(client()->fetch('gen_abc')->status)->toBe($expected);
    })->with([
        ['queued', GenerationStatus::Queued],
        ['pending', GenerationStatus::Queued],
        ['running', GenerationStatus::Processing],
        ['processing', GenerationStatus::Processing],
        ['completed', GenerationStatus::Succeeded],
        ['succeeded', GenerationStatus::Succeeded],
        ['errored', GenerationStatus::Failed],
        ['cancelled', GenerationStatus::Failed],
    ]);

    it('refuses a status it does not recognise rather than guessing', function () {
        Http::fake(['*' => Http::response(['id' => 'gen_abc', 'status' => 'partially_done'])]);

        expect(fn () => client()->fetch('gen_abc'))->toThrow(InvalidRequest::class);
    });
});
