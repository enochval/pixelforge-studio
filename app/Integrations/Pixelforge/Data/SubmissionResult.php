<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge\Data;

use App\Domain\GenerationStatus;

/**
 * What the provider returned when it accepted a submission.
 *
 * The rates are the reason this carries more than an identifier. The provider
 * fixes a generation's rates when it creates it and returns them here, so a
 * later change to the published table cannot alter what this generation costs.
 * Recording them at submission is what makes the eventual charge reproducible.
 */
final readonly class SubmissionResult
{
    /**
     * @param  array<string, string>  $rates  USD per million tokens, by token type
     * @param  bool  $replayed  True when the provider recognised the idempotency
     *                          key and returned the existing generation rather
     *                          than creating one. The status may have moved on
     *                          since the first request, so it is read from the
     *                          response either way.
     */
    public function __construct(
        public string $providerGenerationId,
        public GenerationStatus $status,
        public array $rates = [],
        public bool $replayed = false,
    ) {}
}
