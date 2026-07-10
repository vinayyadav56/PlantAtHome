<?php

namespace App\Modules\Configuration\Providers;

use App\Shared\Http\Middleware\ForceJsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Configuration Engine (v2) context: its migrations and its
 * /api/v1/config route group. Services (ConfigurationService, ConfigAdminService)
 * auto-resolve — the outbox EventPublisher they depend on is bound by the shared
 * kernel.
 */
class ConfigurationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        Route::prefix('api/v1')
            ->middleware(['api', ForceJsonResponse::class])
            ->group(__DIR__.'/../Http/routes.php');
    }
}
