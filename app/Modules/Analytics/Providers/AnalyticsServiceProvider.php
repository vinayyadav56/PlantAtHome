<?php

namespace App\Modules\Analytics\Providers;

use App\Modules\Analytics\Application\Subscribers\RecordAnalytics;
use App\Shared\Events\SubscriberRegistry;
use App\Shared\Http\Middleware\ForceJsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AnalyticsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        Route::prefix('api/v1')
            ->middleware(['api', ForceJsonResponse::class])
            ->group(__DIR__.'/../Http/routes.php');

        $this->app->make(SubscriberRegistry::class)
            ->listen('sales.order_placed', RecordAnalytics::class);
    }
}
