<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

/**
 * The rates a generation is billed at, together with the token model needed to
 * work out what it will cost before it runs.
 *
 * The provider publishes rates in US dollars per million tokens, as decimal
 * strings, and fixes them per generation at the moment it is created — the
 * create response and the generation object both carry the rates that
 * generation will be billed at, so a later price change cannot alter a
 * historical cost. This class is therefore built from two different sources
 * depending on what is being priced: from GET /v1/pricing when quoting a
 * request that has not been submitted, and from the rates stored on the
 * generation row when charging one that has completed.
 *
 * Rates are converted to integer nanodollars per token once, on construction.
 * Every later calculation is integer arithmetic. See Money.
 *
 * The markup is the one value here that is not the provider's. It sits
 * alongside their rates because it is the other half of the same calculation
 * and both are needed wherever a price is produced; keeping it apart would mean
 * passing two objects everywhere one is passed now.
 */
final readonly class RateCard
{
    /**
     * @param  array<string, array<string, int>>  $outputTokens  Keyed by quality, then size
     * @param  array<string, int>  $maximumReasoningTokens  Keyed by quality
     */
    public function __construct(
        public Money $inputTextPerToken,
        public Money $inputImagePerToken,
        public Money $outputPerToken,
        public Money $reasoningPerToken,
        public int $markupBasisPoints,
        public int $charactersPerTextToken,
        public int $textTokenVarianceBasisPoints,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $maximumReasoningTokens,
    ) {
        if ($markupBasisPoints < 0) {
            throw new InvalidArgumentException("Markup cannot be negative: {$markupBasisPoints} bps.");
        }

        if ($charactersPerTextToken < 1) {
            throw new InvalidArgumentException('charactersPerTextToken must be at least 1.');
        }

        if ($textTokenVarianceBasisPoints < 0) {
            throw new InvalidArgumentException('Text token variance cannot be negative.');
        }

        if ($tokensPerReferenceImage < 0) {
            throw new InvalidArgumentException('Tokens per reference image cannot be negative.');
        }
    }

    /**
     * Build from the shape GET /v1/pricing returns, which is also the shape
     * config/pixelforge.php holds as a fallback.
     *
     * The variance the provider publishes is a fraction — 0.15 — and is stored
     * here in basis points so that applying it stays integer arithmetic.
     *
     * @param  array<string, mixed>  $pricing
     */
    public static function fromPricingPayload(array $pricing, int $markupBasisPoints): self
    {
        /** @var array<string, string> $rates */
        $rates = $pricing['rates'] ?? [];

        /** @var array<string, mixed> $tokens */
        $tokens = $pricing['tokens'] ?? [];

        /** @var array<string, array<string, int>> $output */
        $output = $tokens['output'] ?? [];

        /** @var array<string, array<string, int>> $reasoning */
        $reasoning = $tokens['reasoning'] ?? [];

        $variance = (string) ($tokens['text_token_variance'] ?? '0');

        return new self(
            inputTextPerToken: Money::fromUsdPerMillionTokens((string) ($rates['input_text_tokens'] ?? '0')),
            inputImagePerToken: Money::fromUsdPerMillionTokens((string) ($rates['input_image_tokens'] ?? '0')),
            outputPerToken: Money::fromUsdPerMillionTokens((string) ($rates['output_tokens'] ?? '0')),
            reasoningPerToken: Money::fromUsdPerMillionTokens((string) ($rates['reasoning_tokens'] ?? '0')),
            markupBasisPoints: $markupBasisPoints,
            charactersPerTextToken: (int) ($tokens['characters_per_text_token'] ?? 4),
            textTokenVarianceBasisPoints: (int) bcmul($variance, '10000', 0),
            tokensPerReferenceImage: (int) ($tokens['per_reference_image'] ?? 0),
            outputTokens: $output,
            maximumReasoningTokens: array_map(
                static fn (array $range): int => (int) ($range['max'] ?? 0),
                $reasoning,
            ),
        );
    }

    /**
     * Build from the rates recorded on a generation, using the token model from
     * a reference card.
     *
     * A completed generation is charged on the rates it was created at, which
     * the provider returns on the generation object. The token model is not
     * part of those rates and is not needed to charge — the provider has
     * reported the actual counts by then — so it is carried over unchanged.
     *
     * @param  array<string, string>  $rates
     */
    public function withRates(array $rates): self
    {
        return new self(
            inputTextPerToken: Money::fromUsdPerMillionTokens((string) ($rates['input_text_tokens'] ?? '0')),
            inputImagePerToken: Money::fromUsdPerMillionTokens((string) ($rates['input_image_tokens'] ?? '0')),
            outputPerToken: Money::fromUsdPerMillionTokens((string) ($rates['output_tokens'] ?? '0')),
            reasoningPerToken: Money::fromUsdPerMillionTokens((string) ($rates['reasoning_tokens'] ?? '0')),
            markupBasisPoints: $this->markupBasisPoints,
            charactersPerTextToken: $this->charactersPerTextToken,
            textTokenVarianceBasisPoints: $this->textTokenVarianceBasisPoints,
            tokensPerReferenceImage: $this->tokensPerReferenceImage,
            outputTokens: $this->outputTokens,
            maximumReasoningTokens: $this->maximumReasoningTokens,
        );
    }

    /**
     * The sizes and qualities this card can price.
     *
     * Request validation is driven from here rather than from a hardcoded list,
     * so a size the application cannot price can never be accepted.
     *
     * @return array<int, string>
     */
    public function qualities(): array
    {
        return array_keys($this->outputTokens);
    }

    /**
     * @return array<int, string>
     */
    public function sizes(): array
    {
        $sizes = [];

        foreach ($this->outputTokens as $perSize) {
            $sizes = [...$sizes, ...array_keys($perSize)];
        }

        return array_values(array_unique($sizes));
    }

    public function canPrice(string $size, string $quality): bool
    {
        return isset($this->outputTokens[$quality][$size]);
    }

    /**
     * Output tokens for a size and quality. Exact, not an estimate: the
     * provider publishes these as fixed counts.
     */
    public function outputTokensFor(string $size, string $quality): int
    {
        if (! $this->canPrice($size, $quality)) {
            throw new InvalidArgumentException("No output token count published for size {$size} at quality {$quality}.");
        }

        return $this->outputTokens[$quality][$size];
    }

    /**
     * The most reasoning tokens a generation at this quality can use.
     *
     * Used as the ceiling for a pre-submission figure. The actual count is only
     * known on completion and varies per generation.
     */
    public function maximumReasoningTokensFor(string $quality): int
    {
        $maximum = $this->maximumReasoningTokens[$quality] ?? null;

        if ($maximum === null) {
            throw new InvalidArgumentException("No reasoning token range published for quality {$quality}.");
        }

        return $maximum;
    }

    /**
     * The rates in the provider's own form — US dollars per million tokens — so
     * that what is stored on a generation round-trips back through withRates().
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'input_text_tokens' => $this->inputTextPerToken->toUsdPerMillionTokens(),
            'input_image_tokens' => $this->inputImagePerToken->toUsdPerMillionTokens(),
            'output_tokens' => $this->outputPerToken->toUsdPerMillionTokens(),
            'reasoning_tokens' => $this->reasoningPerToken->toUsdPerMillionTokens(),
        ];
    }
}
