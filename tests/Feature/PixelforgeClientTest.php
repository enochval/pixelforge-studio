<?php

declare(strict_types=1);

use App\Domain\GenerationRequest;
use App\Domain\GenerationStatus;
use App\Integrations\Pixelforge\ConcurrencyLimiter;
use App\Integrations\Pixelforge\Data\GenerationSubmission;
use App\Integrations\Pixelforge\Exceptions\AuthenticationFailed;
use App\Integrations\Pixelforge\Exceptions\ConcurrencyLimited;
use App\Integrations\Pixelforge\Exceptions\ContentPolicyViolation;
use App\Integrations\Pixelforge\Exceptions\DailyQuotaExhausted;
use App\Integrations\Pixelforge\Exceptions\GenerationNotFound;
use App\Integrations\Pixelforge\Exceptions\IdempotencyKeyReused;
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
        request: new GenerationRequest($prompt, '1024x1024', 'medium'),
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
            'usage' => ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 1100, 'reasoning_tokens' => 840],
            'image_url' => 'https://images.test/gen_abc.png',
        ])]);

        $generation = client()->fetch('gen_abc');

        expect($generation->status)->toBe(GenerationStatus::Succeeded)
            ->and($generation->usage->outputTokens)->toBe(1100)
            ->and($generation->usage->inputTextTokens)->toBe(12)
            ->and($generation->usage->inputImageTokens)->toBe(765)
            ->and($generation->usage->reasoningTokens)->toBe(840)
            ->and($generation->usage->totalTokens())->toBe(2717)
            ->and($generation->imageUrl)->toBe('https://images.test/gen_abc.png')
            ->and($generation->succeeded())->toBeTrue();
    });

    it('translates a failed generation and its reason', function () {
        Http::fake(['*' => Http::response([
            'id' => 'gen_abc',
            'status' => 'failed',
            'error' => ['code' => 'generation_failed', 'message' => 'The prompt was rejected.'],
        ])]);

        $generation = client()->fetch('gen_abc');

        expect($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->failureCode)->toBe('generation_failed')
            ->and($generation->failureMessage)->toBe('The prompt was rejected.')
            ->and($generation->usage)->toBeNull()
            ->and($generation->isTerminal())->toBeTrue();
    });

    it('maps the provider vocabulary onto this application\'s own', function (string $providerStatus, GenerationStatus $expected) {
        Http::fake(['*' => Http::response(['id' => 'gen_abc', 'status' => $providerStatus])]);

        expect(client()->fetch('gen_abc')->status)->toBe($expected);
    })->with([
        // The provider publishes exactly these four. Accepting synonyms it
        // does not send would be guesswork dressed as tolerance.
        ['queued', GenerationStatus::Queued],
        ['processing', GenerationStatus::Processing],
        ['succeeded', GenerationStatus::Succeeded],
        ['failed', GenerationStatus::Failed],
    ]);

    it('refuses a status it does not recognise rather than guessing', function () {
        // Mapping an unknown status onto a terminal one would record an
        // outcome the provider never reported, and charge for it.
        Http::fake(['*' => Http::response(['id' => 'gen_abc', 'status' => 'partially_done'])]);

        expect(fn () => client()->fetch('gen_abc'))->toThrow(InvalidRequest::class);
    });

    it('records the rates the generation was fixed at', function () {
        Http::fake(['*' => Http::response([
            'id' => 'gen_abc',
            'status' => 'queued',
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
        ])]);

        expect(client()->fetch('gen_abc')->rates)->toBe([
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ]);
    });

    it('reads back our own identifier from the metadata it echoes', function () {
        // This is what lets a webhook be matched to a row before the
        // provider's own identifier has been recorded.
        Http::fake(['*' => Http::response([
            'id' => 'gen_abc',
            'status' => 'processing',
            'metadata' => ['generation_id' => '0199c0ff-ee00-7000-8000-00000000beef'],
        ])]);

        expect(client()->fetch('gen_abc')->clientReference())
            ->toBe('0199c0ff-ee00-7000-8000-00000000beef');
    });

    it('records when the signed image URL stops working', function () {
        Http::fake(['*' => Http::response([
            'id' => 'gen_abc',
            'status' => 'succeeded',
            'usage' => ['input_text_tokens' => 12, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 840],
            'image_url' => 'https://images.test/gen_abc.png',
            'image_url_expires_at' => '2026-11-26T14:03:29Z',
        ])]);

        expect(client()->fetch('gen_abc')->imageUrlExpiresAt?->format('Y-m-d H:i:s'))
            ->toBe('2026-11-26 14:03:29');
    });
});

describe('telling the provider failures apart', function () {
    /**
     * Three unrelated conditions share status 429, and they call for three
     * different responses: wait, release the job, or stop. Dispatching on the
     * status alone would collapse them into one — which is how a daily quota
     * that resets at midnight gets retried for ten minutes, consuming the
     * per-minute allowance other work needs on every attempt.
     */
    it('maps each published error code onto the decision it calls for', function (int $status, string $code, string $expected) {
        Http::fake(['*' => Http::response([
            'error' => ['code' => $code, 'message' => 'from the provider'],
        ], $status)]);

        expect(fn () => client(maxAttempts: 1)->submit(submission()))->toThrow($expected);
    })->with([
        'bad credentials' => [401, 'invalid_api_key', AuthenticationFailed::class],
        'a malformed parameter' => [422, 'validation_failed', InvalidRequest::class],
        'a refused prompt' => [400, 'content_policy_violation', ContentPolicyViolation::class],
        'no such generation' => [404, 'not_found', GenerationNotFound::class],
        'a key reused with different parameters' => [409, 'idempotency_key_reused', IdempotencyKeyReused::class],
        'too many requests this minute' => [429, 'rate_limit_exceeded', RateLimited::class],
        'too many generations at once' => [429, 'concurrency_limit_exceeded', ConcurrencyLimited::class],
        'the daily quota' => [429, 'daily_limit_exceeded', DailyQuotaExhausted::class],
        'a temporary overload' => [503, 'overloaded', ProviderOverloaded::class],
    ]);

    it('does not wait out a quota that resets at midnight', function () {
        // Retrying this cannot succeed within the job's lifetime, so it is
        // reported straight away rather than after every attempt is spent.
        Http::fake(['*' => Http::response([
            'error' => ['code' => 'daily_limit_exceeded', 'message' => 'quota used up'],
        ], 429)]);

        expect(fn () => client()->submit(submission()))->toThrow(DailyQuotaExhausted::class);

        Http::assertSentCount(1);
    });

    it('does not retry a prompt the provider has refused', function () {
        Http::fake(['*' => Http::response([
            'error' => ['code' => 'content_policy_violation', 'message' => 'rejected'],
        ], 400)]);

        expect(fn () => client()->submit(submission()))->toThrow(ContentPolicyViolation::class);

        Http::assertSentCount(1);
    });

    it('honours the Retry-After the provider sends', function () {
        Http::fake(['*' => Http::response(
            ['error' => ['code' => 'concurrency_limit_exceeded', 'message' => 'no slot']],
            429,
            ['Retry-After' => '5'],
        )]);

        try {
            client(maxAttempts: 1)->submit(submission());
        } catch (ConcurrencyLimited $e) {
            expect($e->retryAfterSeconds)->toBe(5);
        }
    });

    it('treats an unrecognised 429 as a rate limit', function () {
        // The safest of the three to assume. Waiting costs time; assuming a
        // concurrency limit would release the job to be retried at once, into
        // the limit it just hit.
        Http::fake(['*' => Http::response(['error' => ['code' => 'some_new_limit']], 429)]);

        expect(fn () => client(maxAttempts: 1)->submit(submission()))->toThrow(RateLimited::class);
    });
});

describe('submitting', function () {
    it('sends the fields the provider documents, under the names it documents', function () {
        Http::fake(['*' => Http::response(['id' => 'gen_abc', 'status' => 'queued'], 202)]);

        client()->submit(new GenerationSubmission(
            request: new GenerationRequest('a lighthouse', '1536x1024', 'high', ['https://example.test/style.png']),
            idempotencyKey: 'idem-7',
            clientReference: 'our-uuid-7',
        ));

        Http::assertSent(function (Request $request): bool {
            return $request['prompt'] === 'a lighthouse'
                && $request['size'] === '1536x1024'
                && $request['quality'] === 'high'
                && $request['reference_images'] === ['https://example.test/style.png']
                && $request['metadata'] === ['generation_id' => 'our-uuid-7']
                && $request->hasHeader('Idempotency-Key', 'idem-7');
        });
    });

    it('records the rates the provider fixed on acceptance', function () {
        Http::fake(['*' => Http::response([
            'id' => 'gen_abc',
            'status' => 'queued',
            'rates' => ['input_text_tokens' => '5.00', 'output_tokens' => '40.00'],
        ], 202)]);

        $result = client()->submit(submission());

        expect($result->rates)->toBe(['input_text_tokens' => '5.00', 'output_tokens' => '40.00'])
            ->and($result->replayed)->toBeFalse();
    });

    it('notices when the provider replayed an earlier submission', function () {
        // The response to a retry that the provider recognised. Nothing new was
        // created and nothing extra was billed, and the status may have moved
        // on since the original request — so it is read from the response
        // rather than assumed to still be queued.
        Http::fake(['*' => Http::response(
            ['id' => 'gen_abc', 'status' => 'processing'],
            202,
            ['Idempotent-Replayed' => 'true'],
        )]);

        $result = client()->submit(submission());

        expect($result->replayed)->toBeTrue()
            ->and($result->status)->toBe(GenerationStatus::Processing);
    });
});

describe('reading the published pricing', function () {
    it('asks for the pricing table without the API key', function () {
        // The endpoint needs no authentication, and sending a credential where
        // none is required widens where it can leak for no benefit.
        Http::fake(['*' => Http::response(['object' => 'pricing', 'rates' => []])]);

        client()->pricing();

        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
    });

    it('returns the table as the provider publishes it', function () {
        Http::fake(['*' => Http::response([
            'object' => 'pricing',
            'unit' => 'per_1m_tokens',
            'rates' => ['output_tokens' => '40.00'],
        ])]);

        expect(client()->pricing()['rates']['output_tokens'])->toBe('40.00');
    });
});
