<?php

namespace App\Modules\Inventory\Providers;

use App\Modules\Inventory\Application\InventoryService;
use App\Modules\Inventory\Console\ReleaseExpiredReservationsCommand;
use App\Shared\Events\EventPublisher;
use App\Shared\Http\Middleware\ForceJsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Inventory (v2) context: the InventoryService (with an explicit DB
 * connection so its FOR UPDATE locks run on the same connection as its models),
 * migrations, the /api/v1/inventory routes, and the expiry-sweep command.
 */
class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InventoryService::class, fn ($app) => new InventoryService(
            $app['db']->connection(),
            $app->make(EventPublisher::class),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        Route::prefix('api/v1')
            ->middleware(['api', ForceJsonResponse::class])
            ->group(__DIR__.'/../Http/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([ReleaseExpiredReservationsCommand::class]);
        }
    }
}
