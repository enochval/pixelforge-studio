<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge\Contracts;

use App\Integrations\Pixelforge\Data\GenerationSubmission;
use App\Integrations\Pixelforge\Data\ProviderGeneration;
use App\Integrations\Pixelforge\Data\SubmissionResult;
use App\Integrations\Pixelforge\Exceptions\ProviderException;
use Carbon\CarbonImmutable;

/**
 * The boundary between this application and the image generation provider.
 *
 * Everything above this interface — submission, reconciliation, charging — is
 * written against it rather than against an HTTP client, which is what allows
 * those parts to be built and tested without the provider being reachable.
 * The in-memory implementation is what justifies the abstraction: it exists to
 * make the application testable, rather than in anticipation of a second
 * provider being added.
 */
interface ImageProvider
{
    /**
     * Submit a generation for processing.
     *
     * @throws ProviderException
     */
    public function submit(GenerationSubmission $submission): SubmissionResult;

    /**
     * Read the provider's current view of a generation.
     *
     * Used by reconciliation when a webhook has not arrived, and never as the
     * primary path.
     *
     * @throws ProviderException
     */
    public function fetch(string $providerGenerationId): ProviderGeneration;

    /**
     * The provider's published rates and token model.
     *
     * Part of this boundary rather than of the HTTP client alone, because
     * quoting a price is something the application does on every request and
     * therefore something a test has to be able to do without a network.
     *
     * Returned in the provider's own shape rather than as a RateCard: this
     * interface describes what the provider says, and interpreting it is
     * RateCard's job.
     *
     * @return array<string, mixed>
     *
     * @throws ProviderException
     */
    public function pricing(): array;

    /**
     * What the provider billed, per UTC day, for the key making the call.
     *
     * The source of truth for provider costs, and therefore what this
     * application's own records are reconciled against. Returned in the
     * provider's own shape for the same reason as pricing(): this interface
     * describes what the provider says, not what it means.
     *
     * Note that a generation is billed on the day it *completed*, not the day
     * it was created, which is the grouping any comparison has to match.
     *
     * @return array<string, mixed>
     *
     * @throws ProviderException
     */
    public function usage(CarbonImmutable $from, CarbonImmutable $to): array;
}
