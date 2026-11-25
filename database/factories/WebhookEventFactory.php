<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\WebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookEvent>
 */
final class WebhookEventFactory extends Factory
{
    protected $model = WebhookEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $providerGenerationId = 'gen_'.Str::random(16);

        return [
            'provider_event_id' => 'evt_'.Str::random(16),
            'event_type' => 'generation.succeeded',
            'provider_generation_id' => $providerGenerationId,
            'payload' => [
                'id' => 'evt_'.Str::random(16),
                'type' => 'generation.succeeded',
                'data' => ['generation_id' => $providerGenerationId],
            ],
            'outcome' => null,
            'received_at' => now(),
            'processed_at' => null,
        ];
    }
}
