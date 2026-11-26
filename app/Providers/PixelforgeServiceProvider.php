<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Pricing;
use App\Integrations\Pixelforge\ConcurrencyLimiter;
use App\Integrations\Pixelforge\Contracts\ImageProvider;
use App\Integrations\Pixelforge\PixelforgeClient;
use App\Integrations\Pixelforge\RateCardRepository;
use App\Integrations\Pixelforge\RetryPolicy;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

final class PixelforgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RetryPolicy::class, fn () => new RetryPolicy);

        $this->app->singleton(ConcurrencyLimiter::class, fn ($app) => new ConcurrencyLimiter(
            redis: $app->make(RedisFactory::class),
            maxConcurrent: (int) config('pixelforge.limits.max_concurrent_generations'),
        ));

        // Everything above the provider depends on the interface. Swapping the
        // binding for the fake is all a test needs to do to run the whole
        // application without a network.
        $this->app->bind(ImageProvider::class, fn ($app) => new PixelforgeClient(
            http: $app->make(HttpFactory::class),
            retries: $app->make(RetryPolicy::class),
            limiter: $app->make(ConcurrencyLimiter::class),
            baseUrl: (string) config('pixelforge.api.base_url'),
            apiKey: (string) config('pixelforge.api.key'),
            timeoutSeconds: (int) config('pixelforge.api.timeout_seconds'),
        ));

        $this->app->singleton(RateCardRepository::class, fn ($app) => new RateCardRepository(
            provider: $app->make(ImageProvider::class),
            cache: $app->make(Cache::class),
            log: $app->make(LoggerInterface::class),
        ));

        // Bound per resolution rather than as a singleton. The rate card behind
        // it is read from a cache that expires, so pinning one card for the
        // lifetime of a worker process would quote from a table that could be
        // days old. Resolving per use costs a cache read.
        $this->app->bind(Pricing::class, fn ($app) => new Pricing(
            $app->make(RateCardRepository::class)->current(),
        ));
    }
}
