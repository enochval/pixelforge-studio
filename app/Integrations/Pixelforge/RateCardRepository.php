<?php

declare(strict_types=1);

namespace App\Integrations\Pixelforge;

use App\Domain\RateCard;
use App\Integrations\Pixelforge\Contracts\ImageProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Supplies the rate card used to quote a price before a generation is submitted.
 *
 * The provider states that rates can change and should be read from its pricing
 * endpoint rather than hardcoded. Reading them on every quote would put a
 * network call in front of a page render and make the application's
 * availability depend on the provider's, so the response is cached.
 *
 * There are three layers, and the order matters:
 *
 *   The cache, refreshed on expiry. Rates change rarely, and an hour-old table
 *   is accurate enough for a quote.
 *
 *   A live call to the pricing endpoint on a cache miss.
 *
 *   The table committed to configuration, when that call fails. A quote has to
 *   remain possible when the provider is unreachable: refusing to show a price
 *   because a pricing endpoint is down would take the product offline for a
 *   reason the user cannot act on.
 *
 * None of this affects what a user is charged. A completed generation is
 * charged on the rates the provider fixed when it created that generation and
 * returned on the generation itself, which are recorded on the row at
 * submission. So a stale cache, or a fallback standing in for a rate that has
 * since changed, can make a quote slightly wrong — never a charge.
 */
final readonly class RateCardRepository
{
    private const CACHE_KEY = 'pixelforge:pricing';

    public function __construct(
        private ImageProvider $provider,
        private Cache $cache,
        private LoggerInterface $log,
    ) {}

    public function current(): RateCard
    {
        return RateCard::fromPricingPayload(
            $this->pricingPayload(),
            (int) config('pixelforge.markup_basis_points'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function pricingPayload(): array
    {
        /** @var array<string, mixed>|null $cached */
        $cached = $this->cache->get(self::CACHE_KEY);

        if ($cached !== null) {
            return $cached;
        }

        try {
            $pricing = $this->provider->pricing();
        } catch (Throwable $e) {
            // Logged rather than swallowed: a quote built on the committed
            // table is still correct for as long as the published rates have
            // not moved, but nobody would know the endpoint had stopped
            // answering until a quote was visibly wrong.
            $this->log->warning('Falling back to the committed Pixelforge rate table.', [
                'reason' => $e->getMessage(),
            ]);

            /** @var array<string, mixed> $fallback */
            $fallback = config('pixelforge.pricing.fallback');

            return $fallback;
        }

        $this->cache->put(
            self::CACHE_KEY,
            $pricing,
            (int) config('pixelforge.pricing.cache_seconds'),
        );

        return $pricing;
    }

    /**
     * Discard the cached table so the next quote reads the endpoint again.
     *
     * Exists so that a rate change does not have to be waited out.
     */
    public function forget(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }
}
