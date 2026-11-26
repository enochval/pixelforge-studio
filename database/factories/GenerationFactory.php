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
     * The provider's published rates, as they are recorded on a generation.
     *
     * @var array<string, string>
     */
    private const RATES = [
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
        'reasoning_tokens' => '12.50',
    ];

    /**
     * A medium quality square generation, quoted at its ceiling.
     *
     * The figures are the real ones for that request rather than round
     * placeholders, so a test reading them is reading something the pricing
     * logic would actually produce: 29 text tokens at the top of the published
     * variance, 1,100 output tokens from the published table, and 2,000
     * reasoning tokens at the top of the medium range.
     *
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
            'quality' => 'medium',
            'reference_image_urls' => [],
            'status' => GenerationStatus::Creating,
            'quoted_nanos' => Money::fromUsd('0.086431250'),
            'quoted_usage' => [
                'input_text_tokens' => 29,
                'input_image_tokens' => 0,
                'output_tokens' => 1_100,
                'reasoning_tokens' => 2_000,
            ],
        ];
    }

    public function queued(): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::Queued,
            'provider_generation_id' => 'gen_'.Str::upper(Str::random(26)),
            'rates' => self::RATES,
            'submitted_at' => now(),
        ]);
    }

    public function processing(): static
    {
        return $this->queued()->state(fn () => ['status' => GenerationStatus::Processing]);
    }

    /**
     * A completed generation, priced from the usage below at the rates above:
     * a provider cost of $0.054560000 and, with a 25% markup, a charge of
     * $0.068200000. Both come out under the quote, as a ceiling requires.
     */
    public function succeeded(): static
    {
        return $this->queued()->state(fn () => [
            'status' => GenerationStatus::Succeeded,
            'provider_cost_nanos' => Money::fromUsd('0.054560000'),
            'charged_nanos' => Money::fromUsd('0.068200000'),
            'usage' => [
                'input_text_tokens' => 12,
                'input_image_tokens' => 0,
                'output_tokens' => 1_100,
                'reasoning_tokens' => 840,
            ],
            'image_url' => 'https://example.test/image.png',
            'image_url_expires_at' => now()->addDay(),
            'completed_at' => now(),
        ]);
    }

    /**
     * A failed generation carries no cost. The provider bills only successful
     * generations, and a schema constraint enforces it.
     */
    public function failed(): static
    {
        return $this->queued()->state(fn () => [
            'status' => GenerationStatus::Failed,
            'failure_code' => 'generation_failed',
            'failure_message' => 'The model could not produce an image for this prompt.',
            'completed_at' => now(),
        ]);
    }
}
