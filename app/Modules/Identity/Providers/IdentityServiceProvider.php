<?php

namespace App\Modules\Identity\Providers;

use App\Modules\Identity\Application\AuthService;
use App\Modules\Identity\Application\Subscribers\RecordLoginAudit;
use App\Modules\Identity\Http\Middleware\AuthenticateV1;
use App\Modules\Identity\Http\Middleware\EnsureNurseryScope;
use App\Modules\Identity\Http\Middleware\EnsureRole;
use App\Modules\Identity\Infrastructure\Jwt\JwtCodec;
use App\Modules\Identity\Infrastructure\TokenIssuer;
use App\Shared\Events\EventPublisher;
use App\Shared\Events\SubscriberRegistry;
use App\Shared\Http\Middleware\ForceJsonResponse;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Identity (v2) context: JWT/token bindings, the auth use-case
 * service, HTTP middleware aliases, the module's route group + migrations, and
 * its outbox subscriber. Self-contained — nothing here touches the legacy
 * marvel auth stack.
 */
class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(JwtCodec::class, fn () => new JwtCodec(config('identity.jwt')));

        $this->app->singleton(TokenIssuer::class, fn ($app) => new TokenIssuer(
            $app->make(JwtCodec::class),
            (int) config('identity.jwt.refresh_ttl'),
            $app->make(\Psr\Log\LoggerInterface::class),
        ));

        $this->app->singleton(AuthService::class, fn ($app) => new AuthService(
            $app['db']->connection(),
            $app->make(JwtCodec::class),
            $app->make(TokenIssuer::class),
            $app->make(EventPublisher::class),
        ));
    }

    public function boot(Router $router): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $router->aliasMiddleware('v1.auth', AuthenticateV1::class);
        $router->aliasMiddleware('v1.role', EnsureRole::class);
        $router->aliasMiddleware('v1.nursery', EnsureNurseryScope::class);

        Route::prefix('api/v1')
            ->middleware(['api', ForceJsonResponse::class])
            ->group(__DIR__.'/../Http/routes.php');

        $this->app->make(SubscriberRegistry::class)
            ->listen('identity.user_logged_in', RecordLoginAudit::class);
    }
}
