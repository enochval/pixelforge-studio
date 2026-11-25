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
use App\Integrations\Pixelforge\Exceptions\GenerationNotFound;
use App\Integrations\Pixelforge\Exceptions\InvalidRequest;
use App\Integrations\Pixelforge\Exceptions\ProviderException;
use App\Integrations\Pixelforge\Exceptions\ProviderOverloaded;
use App\Integrations\Pixelforge\Exceptions\ProviderUnavailable;
use App\Integrations\Pixelforge\Exceptions\RateLimited;
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
 * The wire format — paths, field names and status codes — is isolated in the
 * small translation methods at the bottom of this class. They are the only
 * part that changes if the provider's contract differs from what is assumed
 * here; everything above them is independent of it.
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
                // this is what stops a repeat becoming a second generation
                // the user pays for.
                ->withHeader('Idempotency-Key', $submission->idempotencyKey)
                ->post('/v1/generations', [
                    'prompt' => $submission->request->prompt,
                    'size' => $submission->request->size,
                    'quality' => $submission->request->quality,
                    'reference_image_urls' => $submission->request->referenceImageUrls,

                    // Our own identifier. If the provider echoes metadata back
                    // on its webhooks, a delivery can be matched to a
                    // generation before the provider's own identifier has been
                    // recorded, which removes the orphan case entirely. Sent
                    // either way: the application does not depend on it being
                    // returned, and gains if it is.
                    'metadata' => ['generation_id' => $submission->clientReference],
                ]),
        );

        $body = $response->json();

        return new SubmissionResult(
            providerGenerationId: (string) $body['id'],
            status: $this->translateStatus((string) ($body['status'] ?? 'queued')),
        );
    }

    public function fetch(string $providerGenerationId): ProviderGeneration
    {
        $response = $this->send(
            fn (PendingRequest $request): Response => $request->get("/v1/generations/{$providerGenerationId}"),
        );

        return $this->translateGeneration($response->json());
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
    private function send(callable $perform): Response
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $this->limiter->run(function () use ($perform): Response {
                    try {
                        $response = $perform($this->request());
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

    private function request(): PendingRequest
    {
        return $this->http
            ->baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeoutSeconds);
    }

    // ---------------------------------------------------------------------
    // Translation between the provider's wire format and this application's
    // own types. Isolated so a change to their contract is a change here.
    // ---------------------------------------------------------------------

    private function translateFailure(Response $response): ProviderException
    {
        $message = $this->errorMessage($response);
        $retryAfter = $this->retryAfter($response);

        return match (true) {
            $response->status() === 401, $response->status() === 403 => new AuthenticationFailed($message),
            $response->status() === 404 => new GenerationNotFound($message),
            $response->status() === 409 => new ConcurrencyLimited($message, $retryAfter),
            $response->status() === 429 => $this->isConcurrencyLimit($response)
                ? new ConcurrencyLimited($message, $retryAfter)
                : new RateLimited($message, $retryAfter),
            $response->status() === 408 => new ProviderUnavailable($message, $retryAfter),
            $response->serverError() => new ProviderOverloaded($message, $retryAfter),
            default => new InvalidRequest($message),
        };
    }

    /**
     * A rate limit and a concurrency limit can both arrive as 429 and are
     * handled differently, so the error body is inspected to tell them apart.
     * The exact field belongs to the wire format and is confirmed when the API
     * documentation is read. An unrecognised body falls through to a rate
     * limit, which is the safer of the two to assume: waiting is harmless,
     * whereas treating a rate limit as a concurrency problem would release the
     * job to be retried immediately.
     */
    private function isConcurrencyLimit(Response $response): bool
    {
        return str_contains((string) ($response->json('error.code') ?? ''), 'concurrency');
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
     * @param  array<string, mixed>  $body
     */
    private function translateGeneration(array $body): ProviderGeneration
    {
        $status = $this->translateStatus((string) $body['status']);

        return new ProviderGeneration(
            providerGenerationId: (string) $body['id'],
            status: $status,
            usage: isset($body['usage']) ? TokenUsage::fromProviderPayload($body['usage']) : null,
            imageUrl: $body['image_url'] ?? null,
            failureReason: $body['failure_reason'] ?? null,
        );
    }

    private function translateStatus(string $status): GenerationStatus
    {
        return match ($status) {
            'queued', 'pending', 'accepted' => GenerationStatus::Queued,
            'processing', 'running' => GenerationStatus::Processing,
            'succeeded', 'completed' => GenerationStatus::Succeeded,
            'failed', 'errored', 'cancelled' => GenerationStatus::Failed,
            default => throw new InvalidRequest("Unrecognised provider status: {$status}"),
        };
    }
}
