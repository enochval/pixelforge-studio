<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * A price request, validated exactly as a submission is.
 *
 * Deliberately the same rules rather than looser ones. If a request cannot be
 * submitted, quoting it would show a user a price for something they will then
 * be refused — so the quote endpoint rejects precisely what the submit endpoint
 * rejects, and by inheriting the rules it cannot drift out of step with them.
 */
final class QuoteGenerationRequest extends StoreGenerationRequest {}
