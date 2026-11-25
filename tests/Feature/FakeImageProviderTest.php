<?php

declare(strict_types=1);

use App\Domain\GenerationRequest;
use App\Domain\GenerationStatus;
use App\Integrations\Pixelforge\Contracts\ImageProvider;
use App\Integrations\Pixelforge\Data\GenerationSubmission;
use App\Integrations\Pixelforge\Exceptions\GenerationNotFound;
use App\Integrations\Pixelforge\Exceptions\ProviderOverloaded;
use App\Integrations\Pixelforge\FakeImageProvider;

/**
 * The fake stands in for the provider throughout the rest of the suite, so its
 * own behaviour is worth asserting. A test double that quietly drifts from the
 * contract it imitates gives false confidence everywhere it is used.
 */
function fakeSubmission(string $prompt = 'a cat', string $key = 'idem-1'): GenerationSubmission
{
    return new GenerationSubmission(
        request: new GenerationRequest($prompt, '1024x1024', 'standard'),
        idempotencyKey: $key,
        clientReference: 'our-uuid-1',
    );
}

it('satisfies the provider contract', function () {
    expect(new FakeImageProvider)->toBeInstanceOf(ImageProvider::class);
});

it('accepts a submission and reports it as queued', function () {
    $provider = new FakeImageProvider;

    $result = $provider->submit(fakeSubmission());

    expect($result->status)->toBe(GenerationStatus::Queued)
        ->and($result->providerGenerationId)->toStartWith('gen_')
        ->and($provider->fetch($result->providerGenerationId)->status)->toBe(GenerationStatus::Queued);
});

it('returns the original generation when a key is submitted twice', function () {
    // This is what the real Idempotency-Key header buys: a repeat after an
    // ambiguous timeout returns what already exists rather than creating a
    // second generation the user pays for.
    $provider = new FakeImageProvider;

    $first = $provider->submit(fakeSubmission(key: 'idem-same'));
    $second = $provider->submit(fakeSubmission(key: 'idem-same'));

    expect($second->providerGenerationId)->toBe($first->providerGenerationId)
        ->and($provider->generationCount())->toBe(1)
        ->and($provider->submissionCount())->toBe(2);
});

it('treats a different key as a different generation', function () {
    $provider = new FakeImageProvider;

    $first = $provider->submit(fakeSubmission(key: 'idem-1'));
    $second = $provider->submit(fakeSubmission(key: 'idem-2'));

    expect($second->providerGenerationId)->not->toBe($first->providerGenerationId)
        ->and($provider->generationCount())->toBe(2);
});

it('honours the sandbox keyword that forces a failure', function () {
    $provider = new FakeImageProvider;

    $result = $provider->submit(fakeSubmission(prompt: 'a cat [fail]'));

    expect($provider->fetch($result->providerGenerationId)->status)->toBe(GenerationStatus::Failed)
        ->and($provider->fetch($result->providerGenerationId)->failureReason)->not->toBeNull();
});

it('honours the sandbox keyword that holds a generation in progress', function () {
    $provider = new FakeImageProvider;

    $result = $provider->submit(fakeSubmission(prompt: 'a cat [slow]'));

    expect($provider->fetch($result->providerGenerationId)->status)->toBe(GenerationStatus::Processing)
        ->and($provider->fetch($result->providerGenerationId)->isTerminal())->toBeFalse();
});

it('can be driven to a successful outcome with usage attached', function () {
    $provider = new FakeImageProvider;
    $result = $provider->submit(fakeSubmission());

    $provider->complete($result->providerGenerationId);
    $generation = $provider->fetch($result->providerGenerationId);

    expect($generation->succeeded())->toBeTrue()
        ->and($generation->usage->outputTokens)->toBe(1_056)
        ->and($generation->imageUrl)->not->toBeNull();
});

it('can be made to fail a fixed number of submissions', function () {
    $provider = (new FakeImageProvider)->failNextSubmissions(new ProviderOverloaded('busy'), times: 2);

    expect(fn () => $provider->submit(fakeSubmission(key: 'a')))->toThrow(ProviderOverloaded::class)
        ->and(fn () => $provider->submit(fakeSubmission(key: 'b')))->toThrow(ProviderOverloaded::class);

    // The third attempt is allowed through, which is how a retry sequence is
    // exercised without a network.
    expect($provider->submit(fakeSubmission(key: 'c'))->status)->toBe(GenerationStatus::Queued);
});

it('reports an unknown generation rather than returning nothing', function () {
    expect(fn () => (new FakeImageProvider)->fetch('gen_unknown'))
        ->toThrow(GenerationNotFound::class);
});
