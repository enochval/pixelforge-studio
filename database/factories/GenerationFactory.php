<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\GenerationStatus;
use App\Domain\Money;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
final class GenerationFactory extends Factory
{
    protected $model = Generation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'idempotency_key' => Str::uuid()->toString(),
            'provider_generation_id' => null,
            'prompt' => $this->faker->sentence(),
            'size' => '1024x1024',
            'quality' => 'standard',
            'reference_image_urls' => [],
            'status' => GenerationStatus::Creating,
            'estimated_micros' => Money::fromUsd('0.05'),
            'estimated_usage' => ['input_tokens' => 50, 'output_tokens' => 1000, 'reference_images' => 0],
        ];
    }

    public function queued(): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::Queued,
            'provider_generation_id' => 'gen_'.Str::random(16),
            'submitted_at' => now(),
        ]);
    }

    public function processing(): static
    {
        return $this->queued()->state(fn () => ['status' => GenerationStatus::Processing]);
    }

    public function succeeded(): static
    {
        return $this->queued()->state(fn () => [
            'status' => GenerationStatus::Succeeded,
            'provider_cost_micros' => Money::fromUsd('0.043240'),
            'charged_micros' => Money::fromUsd('0.054050'),
            'usage' => ['input_tokens' => 200, 'output_tokens' => 1056, 'reference_images' => 0],
            'image_url' => 'https://example.test/image.png',
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->queued()->state(fn () => [
            'status' => GenerationStatus::Failed,
            'failure_reason' => 'The provider rejected the prompt.',
            'completed_at' => now(),
        ]);
    }
}
