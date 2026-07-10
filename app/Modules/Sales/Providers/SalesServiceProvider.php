<?php

namespace App\Modules\Sales\Providers;

use App\Modules\Configuration\Application\ConfigurationService;
use App\Modules\Inventory\Application\InventoryService;
use App\Modules\Promotions\Application\PromotionService;
use App\Modules\Sales\Application\CheckoutService;
use App\Modules\Sales\Application\OrderService;
use App\Shared\Events\EventPublisher;
use App\Shared\Http\Middleware\ForceJsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Sales (v2) context: cart, checkout, orders. CheckoutService and
 * OrderService get an explicit DB connection so their transactions + FOR UPDATE
 * work on the same connection as their models. CartService auto-resolves.
 */
class SalesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CheckoutService::class, fn ($app) => new CheckoutService(
            $app['db']->connection(),
            $app->make(ConfigurationService::class),
            $app->make(InventoryService::class),
            $app->make(EventPublisher::class),
            $app->make(PromotionService::class),
        ));

        $this->app->bind(OrderService::class, fn ($app) => new OrderService(
            $app['db']->connection(),
            $app->make(InventoryService::class),
            $app->make(EventPublisher::class),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        Route::prefix('api/v1')
            ->middleware(['api', ForceJsonResponse::class])
            ->group(__DIR__.'/../Http/routes.php');
    }
}
