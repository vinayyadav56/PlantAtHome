<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Providers;

use App\Modules\Marketing\Application\AudienceService;
use App\Modules\Marketing\Application\CampaignRunner;
use App\Modules\Marketing\Application\CampaignService;
use App\Modules\Marketing\Application\Channels\ChannelManager;
use App\Modules\Marketing\Application\DeliveryService;
use App\Modules\Marketing\Application\Sql\AudienceQueryRunner;
use App\Modules\Marketing\Application\TemplateService;
use App\Modules\Marketing\Console\DispatchDueCampaignsCommand;
use App\Modules\Marketing\Domain\Sql\SqlSelectGuard;
use App\Modules\Marketing\Infrastructure\Channels\EmailChannel;
use App\Modules\Marketing\Infrastructure\Channels\SmsChannel;
use App\Modules\Marketing\Infrastructure\Channels\WhatsappChannel;
use App\Shared\Events\EventPublisher;
use App\Shared\Http\Middleware\ForceJsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Marketing (v2) context: services with an explicit DB connection (so
 * their transactions share the models' connection), the DI-swappable channel
 * senders, migrations, the /api/v1/marketing admin routes, and the scheduler
 * command. Registered in config/app.php after the other module providers.
 */
final class MarketingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Channel senders (swappable via DI — bind different ones to fake/extend).
        $this->app->singleton(ChannelManager::class, fn ($app) => new ChannelManager([
            $app->make(EmailChannel::class),
            $app->make(SmsChannel::class),
            $app->make(WhatsappChannel::class),
        ]));

        $this->app->singleton(AudienceQueryRunner::class, fn ($app) => new AudienceQueryRunner(
            $app['db']->connection(),
            $app->make(SqlSelectGuard::class),
        ));

        $this->app->singleton(AudienceService::class, fn ($app) => new AudienceService(
            $app['db']->connection(),
            $app->make(AudienceQueryRunner::class),
        ));

        $this->app->singleton(TemplateService::class, fn ($app) => new TemplateService(
            $app['db']->connection(),
        ));

        $this->app->singleton(DeliveryService::class, fn ($app) => new DeliveryService(
            $app['db']->connection(),
        ));

        $this->app->singleton(CampaignRunner::class, fn ($app) => new CampaignRunner(
            $app['db']->connection(),
            $app->make(DeliveryService::class),
        ));

        $this->app->singleton(CampaignService::class, fn ($app) => new CampaignService(
            $app['db']->connection(),
            $app->make(AudienceService::class),
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
            $this->commands([DispatchDueCampaignsCommand::class]);
        }
    }
}
