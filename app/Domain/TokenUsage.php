<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

/**
 * Token counts for one generation.
 *
 * Input tokens are derivable before submission; output tokens are only known
 * once the provider has finished. That asymmetry is the reason a generation
 * carries both an estimated and an actual cost.
 */
final readonly class TokenUsage
{
    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
        public int $referenceImages = 0,
    ) {
        if ($inputTokens < 0 || $outputTokens < 0 || $referenceImages < 0) {
            throw new InvalidArgumentException('Token counts cannot be negative.');
        }
    }

    public static function none(): self
    {
        return new self(0, 0);
    }

    /**
     * @param  array<string, int|null>  $payload
     */
    public static function fromProviderPayload(array $payload): self
    {
        return new self(
            inputTokens: (int) ($payload['input_tokens'] ?? 0),
            outputTokens: (int) ($payload['output_tokens'] ?? 0),
            referenceImages: (int) ($payload['reference_images'] ?? 0),
        );
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'reference_images' => $this->referenceImages,
        ];
    }
}
