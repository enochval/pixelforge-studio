<?php

namespace App\Providers;

use App\Policies\OperatorPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Named ability rather than a check inlined in the route, so the rule
        // lives in one place and can be asserted directly in a test.
        Gate::define('view-operator-report', [OperatorPolicy::class, 'viewReport']);

        Vite::prefetch(concurrency: 3);
    }
}
