<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

/**
 * The provider's published rates, plus the assumptions used to estimate a cost
 * before a generation has run.
 *
 * Rates are held as decimal strings rather than floats, and are converted to
 * integers only once, at the end of a calculation. See Money.
 *
 * Rates are configuration, not code: a change to the published table is a
 * change to config/pixelforge.php, and nothing in the pricing logic moves.
 */
final readonly class RateCard
{
    /**
     * @param  string  $inputPerToken  USD per input token
     * @param  string  $outputPerToken  USD per output token
     * @param  string  $perReferenceImage  USD per supplied reference image
     * @param  int  $markupBasisPoints  Applied to the provider's cost; 2500 bps is 25%
     * @param  int  $charactersPerToken  Divisor used to estimate input tokens from a prompt
     * @param  array<string, array<string, int>>  $estimatedOutputTokens  Keyed by size, then quality
     */
    public function __construct(
        public string $inputPerToken,
        public string $outputPerToken,
        public string $perReferenceImage,
        public int $markupBasisPoints,
        public int $charactersPerToken,
        public array $estimatedOutputTokens,
    ) {
        foreach (['inputPerToken' => $inputPerToken, 'outputPerToken' => $outputPerToken, 'perReferenceImage' => $perReferenceImage] as $name => $rate) {
            if (! preg_match('/^\d+(\.\d+)?$/', $rate)) {
                throw new InvalidArgumentException("Rate {$name} must be a non-negative decimal string, got: {$rate}");
            }
        }

        if ($charactersPerToken < 1) {
            throw new InvalidArgumentException('charactersPerToken must be at least 1.');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            inputPerToken: (string) $config['rates']['input_per_token'],
            outputPerToken: (string) $config['rates']['output_per_token'],
            perReferenceImage: (string) $config['rates']['per_reference_image'],
            markupBasisPoints: (int) $config['markup_basis_points'],
            charactersPerToken: (int) $config['estimation']['characters_per_token'],
            estimatedOutputTokens: $config['estimation']['output_tokens'],
        );
    }

    /**
     * Expected output tokens for a size and quality, used before the provider
     * has reported anything.
     */
    public function estimatedOutputTokensFor(string $size, string $quality): int
    {
        $tokens = $this->estimatedOutputTokens[$size][$quality] ?? null;

        if ($tokens === null) {
            throw new InvalidArgumentException("No output token estimate configured for size {$size} at quality {$quality}.");
        }

        return $tokens;
    }
}
