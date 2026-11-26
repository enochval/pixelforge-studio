<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

/**
 * The four token counts a generation is billed on.
 *
 * They are known at different times, and the difference is what shapes the
 * charging design:
 *
 *   Output tokens are exact before submission — a published table by quality
 *   and size.
 *
 *   Input image tokens are exact before submission — 765 per reference image.
 *
 *   Input text tokens are an approximation until the generation completes,
 *   stated as within 15% of ceil(characters / 4) in either direction.
 *
 *   Reasoning tokens are unknown until completion and vary per generation,
 *   bounded only by a range set by the chosen quality.
 *
 * So no exact figure exists before a generation runs, which is why what is
 * shown up front is a ceiling and the amount charged is computed again from
 * the counts the provider reports.
 */
final readonly class TokenUsage
{
    public function __construct(
        public int $inputTextTokens,
        public int $inputImageTokens,
        public int $outputTokens,
        public int $reasoningTokens,
    ) {
        foreach (func_get_args() as $count) {
            if ($count < 0) {
                throw new InvalidArgumentException('Token counts cannot be negative.');
            }
        }
    }

    public static function none(): self
    {
        return new self(0, 0, 0, 0);
    }

    /**
     * Read the usage object the provider reports on a completed generation.
     *
     * The provider also sends total_tokens. It is deliberately not stored:
     * it is the sum of the other four, and keeping a derived figure alongside
     * its inputs invites the two to disagree.
     *
     * @param  array<string, mixed>  $usage
     */
    public static function fromProviderPayload(array $usage): self
    {
        return new self(
            inputTextTokens: (int) ($usage['input_text_tokens'] ?? 0),
            inputImageTokens: (int) ($usage['input_image_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            reasoningTokens: (int) ($usage['reasoning_tokens'] ?? 0),
        );
    }

    public function totalTokens(): int
    {
        return $this->inputTextTokens
            + $this->inputImageTokens
            + $this->outputTokens
            + $this->reasoningTokens;
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'input_text_tokens' => $this->inputTextTokens,
            'input_image_tokens' => $this->inputImageTokens,
            'output_tokens' => $this->outputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
        ];
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        return self::fromProviderPayload($stored);
    }
}
