<?php

declare(strict_types=1);

use App\Domain\GenerationRequest;
use App\Domain\GenerationStatus;
use App\Domain\Money;
use App\Domain\Pricing;
use App\Integrations\Pixelforge\Contracts\ImageProvider;
use App\Integrations\Pixelforge\FakeImageProvider;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->app->instance(ImageProvider::class, new FakeImageProvider);
    $this->user = User::factory()->create();
    Queue::fake();
});

describe('the screen', function () {
    it('is not reachable without signing in', function () {
        $this->get('/generations')->assertRedirect('/login');
    });

    it('offers only the sizes and qualities that can be priced', function () {
        // The same rate card drives the form and the validation, so a user can
        // never be offered an option that is then refused.
        actingAs($this->user)
            ->get('/generations')
            ->assertInertia(fn ($page) => $page
                ->component('Generations/Index')
                ->where('sizes', ['1024x1024', '1536x1024', '1024x1536'])
                ->where('qualities', ['low', 'medium', 'high']));
    });

    it('shows a user their own generations and no one else\'s', function () {
        $mine = Generation::factory()->succeeded()->create(['user_id' => $this->user->id]);
        Generation::factory()->succeeded()->create(['user_id' => User::factory()->create()->id]);

        actingAs($this->user)
            ->get('/generations')
            ->assertInertia(fn ($page) => $page
                ->has('generations', 1)
                ->where('generations.0.id', $mine->id));
    });

    it('sends the quote and the charge as separate figures', function () {
        $generation = Generation::factory()->succeeded()->create(['user_id' => $this->user->id]);

        actingAs($this->user)
            ->get('/generations')
            ->assertInertia(fn ($page) => $page
                ->where('generations.0.quoted_nanos', $generation->quoted_nanos->nanos)
                ->where('generations.0.charged_nanos', $generation->charged_nanos->nanos)
                ->where('generations.0.is_terminal', true));
    });

    it('sends no charge for a generation still running', function () {
        Generation::factory()->processing()->create(['user_id' => $this->user->id]);

        actingAs($this->user)
            ->get('/generations')
            ->assertInertia(fn ($page) => $page
                ->where('generations.0.charged_nanos', null)
                ->where('generations.0.is_terminal', false));
    });

    it('shows a failed generation as costing nothing', function () {
        // The row renders "No charge" rather than $0.00, which would read as a
        // missing value. What the screen needs for that is a terminal status
        // with no charge against it.
        $failed = Generation::factory()->failed()->create(['user_id' => $this->user->id]);

        actingAs($this->user)
            ->get('/generations')
            ->assertInertia(fn ($page) => $page
                ->where('generations.0.status', 'failed')
                ->where('generations.0.charged_nanos', null)
                ->where('generations.0.failure_message', $failed->failure_message));
    });

    it('withholds an image URL that has already expired', function () {
        // The provider's signed URLs stop working 24 hours after a generation
        // completes. Offering a dead link is worse than offering none: it looks
        // like the application lost the file.
        $expired = Generation::factory()->succeeded()->create([
            'user_id' => $this->user->id,
            'image_url_expires_at' => now()->subHour(),
        ]);

        actingAs($this->user)
            ->get('/generations')
            ->assertInertia(fn ($page) => $page->where('generations.0.image_url', null));

        expect($expired->fresh()->image_url)->not->toBeNull();
    });

    it('offers an image URL that is still live', function () {
        Generation::factory()->succeeded()->create([
            'user_id' => $this->user->id,
            'image_url_expires_at' => now()->addHours(12),
        ]);

        actingAs($this->user)
            ->get('/generations')
            ->assertInertia(fn ($page) => $page->where('generations.0.image_url', 'https://example.test/image.png'));
    });

    it('does not expose internal identifiers to the browser', function () {
        Generation::factory()->succeeded()->create(['user_id' => $this->user->id]);

        actingAs($this->user)
            ->get('/generations')
            ->assertInertia(fn ($page) => $page
                ->missing('generations.0.idempotency_key')
                ->missing('generations.0.provider_generation_id')
                ->missing('generations.0.rates'));
    });

    it('sends the balance so it can be shown beside the costs', function () {
        $this->user->balance_nanos = Money::fromUsd('5.00');
        $this->user->save();

        actingAs($this->user)
            ->get('/generations')
            ->assertInertia(fn ($page) => $page->where('balance', 5_000_000_000));
    });
});

describe('the quote endpoint', function () {
    it('returns the same figure the charge would be measured against', function () {
        // The point of the endpoint: the price on screen comes from the same
        // Pricing class that performs the charge, so the two cannot drift.
        $payload = ['prompt' => str_repeat('a', 400), 'size' => '1536x1024', 'quality' => 'high'];

        $expected = app(Pricing::class)->quote(new GenerationRequest(
            prompt: $payload['prompt'],
            size: $payload['size'],
            quality: $payload['quality'],
        ));

        actingAs($this->user)
            ->postJson('/generations/quote', $payload)
            ->assertOk()
            ->assertExactJson(['nanos' => $expected->nanos]);
    });

    it('matches what the generation is then quoted at when submitted', function () {
        $payload = ['prompt' => 'a lighthouse at dusk', 'size' => '1024x1024', 'quality' => 'medium'];

        $quoted = actingAs($this->user)->postJson('/generations/quote', $payload)->json('nanos');

        actingAs($this->user)->post('/generations', $payload);

        expect(Generation::sole()->quoted_nanos->nanos)->toBe($quoted);
    });

    it('creates nothing', function () {
        actingAs($this->user)
            ->postJson('/generations/quote', ['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low'])
            ->assertOk();

        expect(Generation::count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('refuses what the submit endpoint would refuse', function (array $payload) {
        // Quoting something that cannot be submitted would show a user a price
        // for a request they are about to be refused.
        actingAs($this->user)
            ->postJson('/generations/quote', $payload)
            ->assertStatus(422);
    })->with([
        'no prompt' => [['size' => '1024x1024', 'quality' => 'medium']],
        'unpriceable size' => [['prompt' => 'a cat', 'size' => '64x64', 'quality' => 'medium']],
        'unpriceable quality' => [['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'ultra']],
    ]);

    it('is not reachable without signing in', function () {
        $this->postJson('/generations/quote', ['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low'])
            ->assertUnauthorized();
    });
});

describe('submitting from the screen', function () {
    it('returns to the screen with the generation already visible', function () {
        actingAs($this->user)
            ->from('/generations')
            ->post('/generations', ['prompt' => 'a lighthouse at dusk', 'size' => '1024x1024', 'quality' => 'medium'])
            ->assertRedirect('/generations');

        actingAs($this->user)
            ->get('/generations')
            ->assertInertia(fn ($page) => $page
                ->has('generations', 1)
                ->where('generations.0.status', GenerationStatus::Creating->value));
    });

    it('sends the dashboard to the generations screen', function () {
        // Breeze's dashboard has nothing to show that this screen does not, so
        // it redirects rather than existing as a second, emptier home.
        actingAs($this->user)->get('/dashboard')->assertRedirect('/generations');
    });
});
