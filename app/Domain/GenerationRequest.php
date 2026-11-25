<?php

declare(strict_types=1);

namespace App\Domain;

use InvalidArgumentException;

/**
 * What a user asked for, independent of how it is stored or submitted.
 *
 * Carried into pricing so that an estimate can be produced before anything is
 * persisted or sent, which is what lets the interface show a cost before the
 * user commits to it.
 */
final readonly class GenerationRequest
{
    /**
     * @param  array<int, string>  $referenceImageUrls
     */
    public function __construct(
        public string $prompt,
        public string $size,
        public string $quality,
        public array $referenceImageUrls = [],
    ) {
        if (trim($prompt) === '') {
            throw new InvalidArgumentException('A prompt is required.');
        }
    }

    public function referenceImageCount(): int
    {
        return count($this->referenceImageUrls);
    }

    public function promptLength(): int
    {
        return mb_strlen($this->prompt);
    }
}
