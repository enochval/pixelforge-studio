<?php

declare(strict_types=1);

use App\Actions\SubmitGeneration;
use App\Domain\GenerationRequest;
use App\Domain\GenerationStatus;
use App\Integrations\Pixelforge\Contracts\ImageProvider;
use App\Integrations\Pixelforge\Exceptions\AuthenticationFailed;
use App\Integrations\Pixelforge\Exceptions\ConcurrencyLimited;
use App\Integrations\Pixelforge\Exceptions\InvalidRequest;
use App\Integrations\Pixelforge\Exceptions\ProviderOverloaded;
use App\Integrations\Pixelforge\Exceptions\ProviderUnavailable;
use App\Integrations\Pixelforge\FakeImageProvider;
use App\Jobs\SubmitGenerationToProvider;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->provider = new FakeImageProvider;
    $this->app->instance(ImageProvider::class, $this->provider);

    // The test connection is synchronous, so a dispatched job would run inside
    // the action that queued it. Every test here drives the job deliberately,
    // to separate what the action does from what the job does.
    Queue::fake();
});

function aRequest(string $prompt = 'a lighthouse at dusk'): GenerationRequest
{
    return new GenerationRequest($prompt, '1024x1024', 'standard');
}

describe('accepting a generation', function () {
    it('records the generation before the provider is contacted', function () {
        $user = User::factory()->create();

        $generation = app(SubmitGeneration::class)->execute($user, aRequest());

        // The row exists and is committed while the submission is still only
        // a queued job. A webhook arriving at this moment has something to
        // match against.
        expect($generation->exists)->toBeTrue()
            ->and($generation->status)->toBe(GenerationStatus::Creating)
            ->and($generation->provider_generation_id)->toBeNull()
            ->and($this->provider->submissionCount())->toBe(0);

        Queue::assertPushed(SubmitGenerationToProvider::class);
    });

    it('records the price the user was shown', function () {
        $generation = app(SubmitGeneration::class)->execute(User::factory()->create(), aRequest());

        expect($generation->estimated_micros->isZero())->toBeFalse()
            ->and($generation->estimated_usage)->toHaveKeys(['input_tokens', 'output_tokens'])
            ->and($generation->charged_micros)->toBeNull();
    });

    it('gives every generation its own idempotency key', function () {
        Queue::fake();
        $user = User::factory()->create();

        $first = app(SubmitGeneration::class)->execute($user, aRequest());
        $second = app(SubmitGeneration::class)->execute($user, aRequest());

        expect($first->idempotency_key)->not->toBe($second->idempotency_key);
    });
});

describe('submitting to the provider', function () {
    it('records the provider identifier once the submission is accepted', function () {
        $generation = app(SubmitGeneration::class)->execute(User::factory()->create(), aRequest());

        (new SubmitGenerationToProvider($generation->id))->handle($this->provider);

        $generation->refresh();

        expect($generation->status)->toBe(GenerationStatus::Queued)
            ->and($generation->provider_generation_id)->toStartWith('gen_')
            ->and($generation->submitted_at)->not->toBeNull();
    });

    it('presents the same idempotency key the row was created with', function () {
        $generation = app(SubmitGeneration::class)->execute(User::factory()->create(), aRequest());

        (new SubmitGenerationToProvider($generation->id))->handle($this->provider);

        expect($this->provider->submissions()[0]->idempotencyKey)->toBe($generation->idempotency_key);
    });

    it('sends our own identifier so a webhook can be matched early', function () {
        $generation = app(SubmitGeneration::class)->execute(User::factory()->create(), aRequest());

        (new SubmitGenerationToProvider($generation->id))->handle($this->provider);

        expect($this->provider->submissions()[0]->clientReference)->toBe($generation->id);
    });

    it('does not submit a generation that has already been accepted', function () {
        // A second attempt can be queued while the first is in flight, or run
        // after a webhook has already resolved the generation.
        $generation = Generation::factory()->queued()->create();

        (new SubmitGenerationToProvider($generation->id))->handle($this->provider);

        expect($this->provider->submissionCount())->toBe(0);
    });

    it('does not submit a generation that has already finished', function () {
        $generation = Generation::factory()->succeeded()->create();

        (new SubmitGenerationToProvider($generation->id))->handle($this->provider);

        expect($this->provider->submissionCount())->toBe(0);
    });

    it('ignores a generation that no longer exists', function () {
        (new SubmitGenerationToProvider('00000000-0000-0000-0000-000000000000'))->handle($this->provider);

        expect($this->provider->submissionCount())->toBe(0);
    });
});

describe('when the provider refuses', function () {
    it('lets the queue retry a failure that might pass later', function () {
        $this->provider->failNextSubmissions(new ProviderOverloaded('busy'));
        $generation = app(SubmitGeneration::class)->execute(User::factory()->create(), aRequest());

        expect(fn () => (new SubmitGenerationToProvider($generation->id))->handle($this->provider))
            ->toThrow(ProviderOverloaded::class);

        // Still open, so a later attempt can succeed.
        expect($generation->fresh()->status)->toBe(GenerationStatus::Creating);
    });

    it('treats a timeout as unresolved rather than failed', function () {
        // The request may have been received and acted on. Closing the
        // generation here would charge nothing for work the provider is
        // already doing, and the user would never see the image.
        $this->provider->failNextSubmissions(new ProviderUnavailable('timed out'));
        $generation = app(SubmitGeneration::class)->execute(User::factory()->create(), aRequest());

        expect(fn () => (new SubmitGenerationToProvider($generation->id))->handle($this->provider))
            ->toThrow(ProviderUnavailable::class);

        expect($generation->fresh()->status)->toBe(GenerationStatus::Creating)
            ->and($generation->fresh()->isTerminal())->toBeFalse();
    });

    it('fails immediately when the provider will never accept the request', function () {
        $this->provider->failNextSubmissions(new InvalidRequest('prompt rejected'));
        $generation = app(SubmitGeneration::class)->execute(User::factory()->create(), aRequest());

        (new SubmitGenerationToProvider($generation->id))->handle($this->provider);

        $generation->refresh();

        expect($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->failure_reason)->toBe('prompt rejected')
            ->and($generation->charged_micros)->toBeNull()
            ->and($generation->completed_at)->not->toBeNull();
    });

    it('fails immediately on a credentials problem rather than retrying it', function () {
        $this->provider->failNextSubmissions(new AuthenticationFailed('invalid key'));
        $generation = app(SubmitGeneration::class)->execute(User::factory()->create(), aRequest());

        (new SubmitGenerationToProvider($generation->id))->handle($this->provider);

        expect($generation->fresh()->status)->toBe(GenerationStatus::Failed);
    });

    it('releases the job rather than failing when no slot is free', function () {
        $this->provider->failNextSubmissions(new ConcurrencyLimited('no slot'));
        $generation = app(SubmitGeneration::class)->execute(User::factory()->create(), aRequest());

        $job = new SubmitGenerationToProvider($generation->id);
        $job->job = Mockery::mock(Job::class);
        $job->job->shouldReceive('release')->once();
        $job->job->shouldReceive('isReleased')->andReturn(true);
        $job->job->shouldReceive('isDeletedOrReleased')->andReturn(true);
        $job->job->shouldReceive('hasFailed')->andReturn(false);

        $job->handle($this->provider);

        // Nothing is wrong with the request, so the generation stays open and
        // the worker is freed for other work.
        expect($generation->fresh()->status)->toBe(GenerationStatus::Creating);
    });
});

describe('when every attempt is exhausted', function () {
    it('closes the generation as failed and charges nothing', function () {
        $generation = app(SubmitGeneration::class)->execute(User::factory()->create(), aRequest());

        (new SubmitGenerationToProvider($generation->id))->failed(new ProviderOverloaded('still busy'));

        $generation->refresh();

        // A job that gave up quietly would leave the row in `creating` and a
        // user watching a status that will never change.
        expect($generation->status)->toBe(GenerationStatus::Failed)
            ->and($generation->failure_reason)->toBe('still busy')
            ->and($generation->charged_micros)->toBeNull();
    });

    it('leaves a generation that has already finished alone', function () {
        $generation = Generation::factory()->succeeded()->create();

        (new SubmitGenerationToProvider($generation->id))->failed(new ProviderOverloaded('late failure'));

        expect($generation->fresh()->status)->toBe(GenerationStatus::Succeeded);
    });
});

describe('the endpoint', function () {
    it('requires authentication', function () {
        $this->post('/generations', ['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'standard'])
            ->assertRedirect('/login');
    });

    it('accepts a valid request and returns without waiting for the provider', function () {
        $this->actingAs(User::factory()->create())
            ->from('/dashboard')
            ->post('/generations', [
                'prompt' => 'a lighthouse at dusk',
                'size' => '1024x1024',
                'quality' => 'standard',
            ])
            ->assertRedirect('/dashboard');

        expect(Generation::count())->toBe(1);
        Queue::assertPushed(SubmitGenerationToProvider::class);
    });

    it('rejects a request it could not price', function (array $payload, string $invalidField) {
        $this->actingAs(User::factory()->create())
            ->post('/generations', $payload)
            ->assertSessionHasErrors($invalidField);

        expect(Generation::count())->toBe(0);
    })->with([
        'no prompt' => [['size' => '1024x1024', 'quality' => 'standard'], 'prompt'],
        'unknown size' => [['prompt' => 'a cat', 'size' => '64x64', 'quality' => 'standard'], 'size'],
        'unknown quality' => [['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'ultra'], 'quality'],
        'reference that is not a url' => [[
            'prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'standard',
            'reference_image_urls' => ['not-a-url'],
        ], 'reference_image_urls.0'],
    ]);
});
