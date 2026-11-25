<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Pricing;
use App\Domain\RateCard;
use App\Integrations\Pixelforge\ConcurrencyLimiter;
use App\Integrations\Pixelforge\Contracts\ImageProvider;
use App\Integrations\Pixelforge\PixelforgeClient;
use App\Integrations\Pixelforge\RetryPolicy;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

final class PixelforgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Pricing is bound as a singleton because a rate card is immutable for
        // the lifetime of a request and rebuilding it per resolution would
        // re-validate the same configuration repeatedly.
        $this->app->singleton(Pricing::class, fn () => new Pricing(
            RateCard::fromConfig(config('pixelforge')),
        ));

        $this->app->singleton(RetryPolicy::class, fn () => new RetryPolicy);

        $this->app->singleton(ConcurrencyLimiter::class, fn ($app) => new ConcurrencyLimiter(
            redis: $app->make(RedisFactory::class),
            maxConcurrent: (int) config('pixelforge.api.max_concurrent_requests'),
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
        ));
    }
}
