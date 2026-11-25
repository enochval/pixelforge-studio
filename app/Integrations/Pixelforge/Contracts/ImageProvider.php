<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge\Contracts;

use App\Integrations\Pixelforge\Data\GenerationSubmission;
use App\Integrations\Pixelforge\Data\ProviderGeneration;
use App\Integrations\Pixelforge\Data\SubmissionResult;
use App\Integrations\Pixelforge\Exceptions\ProviderException;

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
}
