<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Every price in the application, quoted or final, is produced here.
 *
 * One implementation serves both the figure shown before submission and the
 * amount charged afterwards. A second implementation in JavaScript, or a
 * separate calculation in the charging path, would eventually disagree with
 * this one, and the price a user agreed to would stop matching the price they
 * were billed.
 *
 * The provider never returns a price. It reports token counts and the rates a
 * generation was fixed at, and the cost is ours to compute — so this class is
 * the only place in the system that knows how.
 *
 * There is exactly one rounding point in the whole pricing path, and it is the
 * markup. A provider cost is a sum of integer products and needs no rounding
 * at all — see Money for why the unit makes that true. The markup then turns
 * that exact integer into a charge, rounding half away from zero once.
 *
 * Rounding the provider cost first, to the nearest cent, would compound an
 * error of up to half a cent on every generation and show up as a growing
 * discrepancy against the provider's own records.
 */
final readonly class Pricing
{
    public function __construct(private RateCard $rates) {}

    /**
     * Price the same request against different rates.
     *
     * A completed generation is charged on the rates the provider fixed when it
     * was created, which it returns on the generation object. Those can differ
     * from the rates currently published, so charging reads them from the
     * generation rather than from configuration.
     *
     * @param  array<string, string>  $rates
     */
    public function withRates(array $rates): self
    {
        return new self($this->rates->withRates($rates));
    }

    public function rateCard(): RateCard
    {
        return $this->rates;
    }

    /**
     * What the provider charged for a generation that has completed. Exact.
     */
    public function providerCost(TokenUsage $usage): Money
    {
        return $this->rates->inputTextPerToken->multipliedBy($usage->inputTextTokens)
            ->plus($this->rates->inputImagePerToken->multipliedBy($usage->inputImageTokens))
            ->plus($this->rates->outputPerToken->multipliedBy($usage->outputTokens))
            ->plus($this->rates->reasoningPerToken->multipliedBy($usage->reasoningTokens));
    }

    /**
     * What a user is charged, given what the provider charged.
     */
    public function charge(Money $providerCost): Money
    {
        return $providerCost->withMarkup($this->rates->markupBasisPoints);
    }

    /**
     * The most tokens a request can use.
     *
     * Two of the four counts are exact before a generation runs and two are
     * not — TokenUsage sets out which, and why. The two that are not take their
     * upper bound here: the top of the published variance band for text, and
     * the top of the published range for reasoning.
     *
     * So this is a ceiling rather than a prediction, deliberately. A figure
     * shown to a user before they commit, and an amount reserved against their
     * balance, both have to be amounts that cannot be exceeded. An average
     * would be wrong roughly half the time, in the direction that leaves a
     * generation unaffordable once it has already run.
     */
    public function maximumUsage(GenerationRequest $request): TokenUsage
    {
        $estimatedTextTokens = (int) ceil(
            $request->promptLength() / $this->rates->charactersPerTextToken,
        );

        return new TokenUsage(
            inputTextTokens: self::increasedBy($estimatedTextTokens, $this->rates->textTokenVarianceBasisPoints),
            inputImageTokens: $request->referenceImageCount() * $this->rates->tokensPerReferenceImage,
            outputTokens: $this->rates->outputTokensFor($request->size, $request->quality),
            reasoningTokens: $this->rates->maximumReasoningTokensFor($request->quality),
        );
    }

    /**
     * The most the provider can charge for a request that has not run yet.
     */
    public function maximumProviderCost(GenerationRequest $request): Money
    {
        return $this->providerCost($this->maximumUsage($request));
    }

    /**
     * The figure quoted to a user before they submit: the ceiling on the
     * provider's cost with the markup applied, because that is the most they
     * can be asked to pay.
     */
    public function quote(GenerationRequest $request): Money
    {
        return $this->charge($this->maximumProviderCost($request));
    }

    /**
     * Raise a token count by a variance in basis points, rounding up.
     *
     * Rounding up matters: this produces a ceiling, and a ceiling that rounds
     * down is not one. Integer arithmetic throughout, because a float here
     * would make the quoted figure platform-dependent.
     */
    private static function increasedBy(int $tokens, int $basisPoints): int
    {
        $scaled = $tokens * (10_000 + $basisPoints);

        return intdiv($scaled + 9_999, 10_000);
    }
}
