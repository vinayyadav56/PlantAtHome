<?php

namespace App\Modules\Search\Providers;

use App\Modules\Search\Application\Subscribers\IndexProductProjection;
use App\Modules\Search\Console\ReindexSearchCommand;
use App\Modules\Search\Infrastructure\EsSearchIndex;
use App\Shared\Events\SubscriberRegistry;
use App\Shared\Http\Middleware\ForceJsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Search (v2) context: the ES driver (from config), migrations, the
 * public /api/v1/search routes, the reindex command, and the outbox subscriber
 * that keeps the projection in sync with the catalog.
 */
class SearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EsSearchIndex::class, fn () => new EsSearchIndex(
            (string) config('search.elasticsearch_host'),
            (string) config('search.index', 'products'),
            (int) config('search.ping_timeout', 2),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        Route::prefix('api/v1')
            ->middleware(['api', ForceJsonResponse::class])
            ->group(__DIR__.'/../Http/routes.php');

        $registry = $this->app->make(SubscriberRegistry::class);
        $registry->listen('catalog.product_published', IndexProductProjection::class);
        $registry->listen('catalog.product_updated', IndexProductProjection::class);

        if ($this->app->runningInConsole()) {
            $this->commands([ReindexSearchCommand::class]);
        }
    }
}
