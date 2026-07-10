<?php

namespace App\Modules\Serviceability\Providers;

use App\Shared\Http\Middleware\ForceJsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Serviceability (v2) context: migrations and the
 * /api/v1/serviceability route group. CoverageService auto-resolves.
 */
class ServiceabilityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        Route::prefix('api/v1')
            ->middleware(['api', ForceJsonResponse::class])
            ->group(__DIR__.'/../Http/routes.php');
    }
}
