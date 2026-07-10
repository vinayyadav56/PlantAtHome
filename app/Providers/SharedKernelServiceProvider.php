<?php

namespace App\Providers;

use App\Modules\Platform\Application\Subscribers\RecordHealthPing;
use App\Shared\Events\EventPublisher;
use App\Shared\Events\OutboxEventPublisher;
use App\Shared\Events\OutboxRelay;
use App\Shared\Events\SubscriberRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Wires the v2 modular-monolith shared kernel: the outbox event bus bindings,
 * the /api/v1 route group, and the (module-owned) subscriber registrations.
 * Additive — it does not touch the legacy marvel routing/bindings.
 */
class SharedKernelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Guarantee a PSR logger is resolvable for kernel/subscriber injection.
        if (! $this->app->bound(LoggerInterface::class)) {
            $this->app->bind(LoggerInterface::class, fn ($app) => $app['log']);
        }

        // Domain events are published through the transactional outbox.
        $this->app->bind(
            EventPublisher::class,
            fn ($app) => new OutboxEventPublisher($app['db']->connection()),
        );

        // One registry for the whole process so module registrations persist.
        $this->app->singleton(
            SubscriberRegistry::class,
            fn ($app) => new SubscriberRegistry($app),
        );

        $this->app->bind(
            OutboxRelay::class,
            fn ($app) => new OutboxRelay(
                $app['db']->connection(),
                $app->make(SubscriberRegistry::class),
                $app->make(LoggerInterface::class),
            ),
        );
    }

    public function boot(): void
    {
        $this->registerSubscribers($this->app->make(SubscriberRegistry::class));

        Route::prefix('api/v1')
            ->middleware('api')
            ->group(base_path('routes/v1.php'));
    }

    /**
     * Module event subscriptions. As modules land, they add their listens here
     * (or in their own provider) — the producer never learns who consumes.
     */
    private function registerSubscribers(SubscriberRegistry $registry): void
    {
        $registry->listen('platform.health_pinged', RecordHealthPing::class);
    }
}
