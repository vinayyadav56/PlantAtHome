<?php

namespace App\Modules\Catalog\Providers;

use App\Shared\Http\Middleware\ForceJsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Master Catalog (v2) context: its migrations and its /api/v1/catalog
 * route group. The application services (CategoryService, ProductService,
 * ProposalService) are auto-resolved by the container — their only dependency,
 * the outbox EventPublisher, is bound by the shared kernel.
 */
class CatalogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        Route::prefix('api/v1')
            ->middleware(['api', ForceJsonResponse::class])
            ->group(__DIR__.'/../Http/routes.php');
    }
}
