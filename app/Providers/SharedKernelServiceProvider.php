<?php

namespace App\Providers;

use App\Modules\Platform\Application\Subscribers\RecordHealthPing;
use App\Shared\Events\EventPublisher;
use App\Shared\Events\OutboxEventPublisher;
use App\Shared\Events\OutboxRelay;
use App\Shared\Events\SubscriberRegistry;
use App\Shared\Http\ApiExceptionRenderer;
use App\Shared\Http\Middleware\ForceJsonResponse;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;
use Throwable;

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
        $this->registerV1ExceptionEnvelope();

        Route::prefix('api/v1')
            ->middleware(['api', ForceJsonResponse::class])
            ->group(base_path('routes/v1.php'));
    }

    /**
     * Ensure every exception thrown while serving an /api/v1 route is rendered
     * as the standard envelope (validation, 404/405, auth, unexpected 500) —
     * not Laravel's default body or a redirect. Scoped to api/v1 so the legacy
     * marvel error handling is untouched.
     */
    private function registerV1ExceptionEnvelope(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        if (! method_exists($handler, 'renderable')) {
            return;
        }

        $handler->renderable(function (Throwable $e, Request $request) {
            if (! $request->is('api/v1', 'api/v1/*')) {
                return null;
            }

            return ApiExceptionRenderer::render($e);
        });
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
