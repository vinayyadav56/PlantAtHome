<?php

namespace App\Modules\Pricing\Providers;

use App\Shared\Http\Middleware\ForceJsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Pricing (v2) context: migrations and the /api/v1/pricing route
 * group. PricingService auto-resolves (it depends only on the RulesEngine).
 */
class PricingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        Route::prefix('api/v1')
            ->middleware(['api', ForceJsonResponse::class])
            ->group(__DIR__.'/../Http/routes.php');
    }
}
