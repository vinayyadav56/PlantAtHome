<?php

namespace Marvel\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;


class RestApiServiceProvider extends ServiceProvider
{

    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->loadRoutes();
    }

    public function loadRoutes(): void
    {
        Route::prefix('api')->group(function () {
            $this->loadRoutesFrom(__DIR__ . '/../Rest/Routes.php');
        });
    }
}
