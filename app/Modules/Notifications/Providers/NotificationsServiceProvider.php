<?php

namespace App\Modules\Notifications\Providers;

use App\Modules\Notifications\Application\Subscribers\DispatchNotifications;
use App\Shared\Events\SubscriberRegistry;
use App\Shared\Http\Middleware\ForceJsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wires Notifications: migrations, admin routes, and the outbox subscriber that
 * turns domain events into notifications (off the request path). Add an event to
 * the listen list here as templates for it are introduced.
 */
class NotificationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        Route::prefix('api/v1')
            ->middleware(['api', ForceJsonResponse::class])
            ->group(__DIR__.'/../Http/routes.php');

        $registry = $this->app->make(SubscriberRegistry::class);
        foreach (['sales.order_placed', 'sales.order_refunded'] as $event) {
            $registry->listen($event, DispatchNotifications::class);
        }
    }
}
