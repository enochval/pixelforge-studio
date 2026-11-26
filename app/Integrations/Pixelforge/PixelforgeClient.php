<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge;

use App\Domain\GenerationStatus;
use App\Domain\TokenUsage;
use App\Integrations\Pixelforge\Contracts\ImageProvider;
use App\Integrations\Pixelforge\Data\GenerationSubmission;
use App\Integrations\Pixelforge\Data\ProviderGeneration;
use App\Integrations\Pixelforge\Data\SubmissionResult;
use App\Integrations\Pixelforge\Exceptions\AuthenticationFailed;
use App\Integrations\Pixelforge\Exceptions\ConcurrencyLimited;
use App\Integrations\Pixelforge\Exceptions\ContentPolicyViolation;
use App\Integrations\Pixelforge\Exceptions\DailyQuotaExhausted;
use App\Integrations\Pixelforge\Exceptions\GenerationNotFound;
use App\Integrations\Pixelforge\Exceptions\IdempotencyKeyReused;
use App\Integrations\Pixelforge\Exceptions\InvalidRequest;
use App\Integrations\Pixelforge\Exceptions\ProviderException;
use App\Integrations\Pixelforge\Exceptions\ProviderOverloaded;
use App\Integrations\Pixelforge\Exceptions\ProviderUnavailable;
use App\Integrations\Pixelforge\Exceptions\RateLimited;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * HTTP client for the provider.
 *
 * Three concerns are kept apart deliberately. Transport lives here, the
 * decision about whether and when to repeat a request lives in RetryPolicy,
 * and the cap on simultaneous requests lives in ConcurrencyLimiter. Each is
 * testable without the other two.
 *
 * The wire format — paths, field names, error codes — is isolated in the
 * translation methods at the bottom of this class. Nothing above them depends
 * on it, so a change to the provider's contract is a change in one place.
 */
final class PixelforgeClient implements ImageProvider
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly RetryPolicy $retries,
        private readonly ConcurrencyLimiter $limiter,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeoutSeconds = 15,
    ) {}

    public function submit(GenerationSubmission $submission): SubmissionResult
    {
        $response = $this->send(
            fn (PendingRequest $request): Response => $request
                // The key travels with every attempt, including retries. A
                // request that timed out may already have been accepted, and
                // this is what stops a repeat becoming a second generation the
                // user pays for. Presented with identical parameters the
                // provider returns the original generation and creates nothing.
                ->withHeader('Idempotency-Key', $submission->idempotencyKey)
                ->post('/generations', [
                    'prompt' => $submission->request->prompt,
                    'size' => $submission->request->size,
                    'quality' => $submission->request->quality,
                    'reference_images' => $submission->request->referenceImageUrls,

                    // Our own identifier, returned on the generation and on
                    // every webhook. It is what allows a delivery to be matched
                    // to a row before the provider's identifier has been
                    // recorded — the documented case where a webhook arrives
                    // before the create request has returned.
                    'metadata' => ['generation_id' => $submission->clientReference],
                ]),
        );

        /** @var array<string, mixed> $body */
        $body = $response->json();

        /** @var array<string, string> $rates */
        $rates = $body['rates'] ?? [];

        return new SubmissionResult(
            providerGenerationId: (string) $body['id'],
            status: $this->translateStatus((string) ($body['status'] ?? 'queued')),
            rates: $rates,

            // The provider sets this header when it recognised the idempotency
            // key and returned an existing generation rather than creating one.
            replayed: $response->header('Idempotent-Replayed') === 'true',
        );
    }

    public function fetch(string $providerGenerationId): ProviderGeneration
    {
        $response = $this->send(
            fn (PendingRequest $request): Response => $request->get("/generations/{$providerGenerationId}"),
        );

        /** @var array<string, mixed> $body */
        $body = $response->json();

        return $this->translateGeneration($body);
    }

    /**
     * The provider's published rates and token model.
     *
     * Needs no authentication and is deliberately sent without the API key.
     * Rates can change, and the provider's guidance is to read them here rather
     * than hardcode them — so this is the source for a quote, while a completed
     * generation is charged on the rates recorded against it.
     *
     * @return array<string, mixed>
     *
     * @throws ProviderException
     */
    public function pricing(): array
    {
        $response = $this->send(
            fn (PendingRequest $request): Response => $request->get('/pricing'),
            authenticated: false,
        );

        /** @var array<string, mixed> $body */
        $body = $response->json();

        return $body;
    }

    /**
     * Performs one logical request: holds a concurrency slot, sends, retries
     * while the policy allows it, and translates any failure into a typed
     * exception.
     *
     * @param  callable(PendingRequest): Response  $perform
     *
     * @throws ProviderException
     */
    private function send(callable $perform, bool $authenticated = true): Response
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $this->limiter->run(function () use ($perform, $authenticated): Response {
                    try {
                        $response = $perform($this->request($authenticated));
                    } catch (ConnectionException $e) {
                        // The request did not complete. For a submission this
                        // is an unknown outcome rather than a failed one.
                        throw new ProviderUnavailable($e->getMessage(), previous: $e);
                    }

                    return $response->failed()
                        ? throw $this->translateFailure($response)
                        : $response;
                });
            } catch (ProviderException $e) {
                $failure = $e;
            }

            if (! $this->retries->shouldRetry($failure, $attempt)) {
                throw $failure;
            }

            usleep($this->retries->delayMilliseconds($attempt, $failure->retryAfterSeconds) * 1_000);
        }
    }

    private function request(bool $authenticated): PendingRequest
    {
        $request = $this->http
            ->baseUrl($this->baseUrl)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeoutSeconds);

        return $authenticated ? $request->withToken($this->apiKey) : $request;
    }

    // ---------------------------------------------------------------------
    // Translation between the provider's wire format and this application's
    // own types. Isolated so a change to their contract is a change here.
    // ---------------------------------------------------------------------

    /**
     * Map a failure onto the exception class that describes what to do about it.
     *
     * Dispatch is on the provider's error code, not the HTTP status, because
     * three unrelated conditions share status 429: a per-minute rate limit, a
     * cap on concurrent generations, and an exhausted daily quota. They call
     * for waiting, releasing the job, and giving up respectively. Branching on
     * the status would collapse them into one and retry a daily quota for ten
     * minutes against a limit that resets at midnight.
     *
     * Status is the fallback for anything the provider adds later, so an
     * unrecognised code still lands in a sensible category rather than being
     * treated as a bad request.
     */
    private function translateFailure(Response $response): ProviderException
    {
        $code = (string) ($response->json('error.code') ?? '');
        $message = $this->errorMessage($response);
        $retryAfter = $this->retryAfter($response);

        return match ($code) {
            'invalid_api_key' => new AuthenticationFailed($message),
            'validation_failed', 'date_range_too_long' => new InvalidRequest($message),
            'content_policy_violation' => new ContentPolicyViolation($message),
            'not_found' => new GenerationNotFound($message),
            'idempotency_key_reused' => new IdempotencyKeyReused($message),
            'rate_limit_exceeded' => new RateLimited($message, $retryAfter),
            'concurrency_limit_exceeded' => new ConcurrencyLimited($message, $retryAfter),
            'daily_limit_exceeded' => new DailyQuotaExhausted($message),
            'overloaded' => new ProviderOverloaded($message, $retryAfter),
            default => $this->translateFailureByStatus($response, $message, $retryAfter),
        };
    }

    private function translateFailureByStatus(Response $response, string $message, ?int $retryAfter): ProviderException
    {
        return match (true) {
            $response->status() === 401, $response->status() === 403 => new AuthenticationFailed($message),
            $response->status() === 404 => new GenerationNotFound($message),

            // An unrecognised 429 is treated as a rate limit, the safest of the
            // three to assume: waiting costs time, whereas assuming a
            // concurrency limit would release the job to be retried at once.
            $response->status() === 429 => new RateLimited($message, $retryAfter),

            $response->status() === 408 => new ProviderUnavailable($message, $retryAfter),
            $response->serverError() => new ProviderOverloaded($message, $retryAfter),
            default => new InvalidRequest($message),
        };
    }

    private function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? (int) $header : null;
    }

    private function errorMessage(Response $response): string
    {
        return (string) ($response->json('error.message')
            ?? "Provider responded with HTTP {$response->status()}.");
    }

    /**
     * @param  array<string, mixed>  $body  A generation object, from a polled
     *                                      response or from inside an event
     */
    public function translateGeneration(array $body): ProviderGeneration
    {
        /** @var array<string, mixed>|null $usage */
        $usage = $body['usage'] ?? null;

        /** @var array<string, string> $rates */
        $rates = $body['rates'] ?? [];

        /** @var array<string, string> $metadata */
        $metadata = $body['metadata'] ?? [];

        /** @var array<string, string>|null $error */
        $error = $body['error'] ?? null;

        return new ProviderGeneration(
            providerGenerationId: (string) $body['id'],
            status: $this->translateStatus((string) $body['status']),

            // Null until the generation succeeds. Failed generations are never
            // billed, so there is nothing to price.
            usage: $usage === null ? null : TokenUsage::fromProviderPayload($usage),

            imageUrl: isset($body['image_url']) ? (string) $body['image_url'] : null,
            imageUrlExpiresAt: $this->timestamp($body['image_url_expires_at'] ?? null),
            failureCode: $error['code'] ?? null,
            failureMessage: $error['message'] ?? null,
            rates: $rates,
            metadata: $metadata,
            completedAt: $this->timestamp($body['completed_at'] ?? null),
        );
    }

    private function timestamp(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) && $value !== ''
            ? new DateTimeImmutable($value)
            : null;
    }

    /**
     * The provider publishes exactly four statuses. An unrecognised one is
     * raised rather than guessed at: silently mapping it onto a terminal state
     * would charge for a generation whose outcome is not actually known.
     */
    private function translateStatus(string $status): GenerationStatus
    {
        return match ($status) {
            'queued' => GenerationStatus::Queued,
            'processing' => GenerationStatus::Processing,
            'succeeded' => GenerationStatus::Succeeded,
            'failed' => GenerationStatus::Failed,
            default => throw new InvalidRequest("Unrecognised provider status: {$status}"),
        };
    }
}
