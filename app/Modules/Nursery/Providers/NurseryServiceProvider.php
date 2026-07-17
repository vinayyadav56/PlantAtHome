<?php

namespace App\Modules\Nursery\Providers;

use App\Modules\Nursery\Application\NurseryService;
use App\Modules\Nursery\Application\Subscribers\ProjectNurseryToLegacyShop;
use App\Modules\Nursery\Application\WithdrawalService;
use App\Modules\Nursery\Console\BackfillNurseriesCommand;
use App\Modules\Nursery\Console\ProjectNurseriesToShopsCommand;
use App\Shared\Events\EventPublisher;
use App\Shared\Events\SubscriberRegistry;
use App\Shared\Http\Middleware\ForceJsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Nursery (v2) context: lifecycle + withdrawal services (explicit DB
 * connection so their FOR UPDATE locks share the models' connection),
 * migrations, the /api/v1 nursery/withdrawal routes, the legacy-shop
 * projection subscriber, and the backfill command.
 */
class NurseryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(NurseryService::class, fn ($app) => new NurseryService(
            $app['db']->connection(),
            $app->make(EventPublisher::class),
        ));

        $this->app->singleton(WithdrawalService::class, fn ($app) => new WithdrawalService(
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

        $registry = $this->app->make(SubscriberRegistry::class);
        foreach (['nursery.approved', 'nursery.updated', 'nursery.commission_changed'] as $event) {
            $registry->listen($event, ProjectNurseryToLegacyShop::class);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([BackfillNurseriesCommand::class, ProjectNurseriesToShopsCommand::class]);
        }
    }
}
