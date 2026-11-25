<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Every price in the application, estimated or final, is produced here.
 *
 * One implementation serves both the figure shown before submission and the
 * amount charged afterwards. A second implementation in JavaScript, or a
 * separate calculation in the charging path, would eventually disagree with
 * this one, and the price a user agreed to would stop matching the price they
 * were billed.
 *
 * Rounding happens at two points, each for a stated reason:
 *
 *   The provider's cost is accumulated at twelve decimal places and rounded
 *   once, to the nearest micro-dollar, when it becomes a Money. That figure is
 *   what gets recorded and what is compared against the provider's own usage
 *   reporting, so it has to be rounded to the precision at which costs are
 *   reported.
 *
 *   The markup is then applied to that exact integer and rounded once more.
 *
 * The alternative — rounding the provider's cost to the nearest cent and
 * marking up the rounded figure — compounds an error of up to half a cent on
 * every generation, which becomes material over thousands of them and would
 * show up as a discrepancy against the provider's records.
 */
final readonly class Pricing
{
    /** Decimal places carried through intermediate arithmetic. */
    private const SCALE = 12;

    public function __construct(private RateCard $rates) {}

    /**
     * What the provider will charge for a generation that has completed.
     */
    public function providerCost(TokenUsage $usage): Money
    {
        $total = bcadd(
            bcadd(
                bcmul((string) $usage->inputTokens, $this->rates->inputPerToken, self::SCALE),
                bcmul((string) $usage->outputTokens, $this->rates->outputPerToken, self::SCALE),
                self::SCALE,
            ),
            bcmul((string) $usage->referenceImages, $this->rates->perReferenceImage, self::SCALE),
            self::SCALE,
        );

        return Money::fromUsd($total);
    }

    /**
     * What a user is charged, given what the provider charged.
     */
    public function charge(Money $providerCost): Money
    {
        return $providerCost->withMarkup($this->rates->markupBasisPoints);
    }

    /**
     * The provider cost expected for a request that has not run yet.
     *
     * Input tokens are derived from the prompt; output tokens come from the
     * configured expectation for the chosen size and quality, because the real
     * count is not knowable until the generation completes.
     */
    public function estimatedProviderCost(GenerationRequest $request): Money
    {
        return $this->providerCost($this->estimatedUsage($request));
    }

    /**
     * The figure shown to a user before they submit: the estimated provider
     * cost with the markup already applied, because that is the amount they
     * would pay.
     */
    public function estimate(GenerationRequest $request): Money
    {
        return $this->charge($this->estimatedProviderCost($request));
    }

    public function estimatedUsage(GenerationRequest $request): TokenUsage
    {
        return new TokenUsage(
            inputTokens: (int) ceil($request->promptLength() / $this->rates->charactersPerToken),
            outputTokens: $this->rates->estimatedOutputTokensFor($request->size, $request->quality),
            referenceImages: $request->referenceImageCount(),
        );
    }
}
