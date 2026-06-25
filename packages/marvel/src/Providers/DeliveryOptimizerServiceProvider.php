<?php

namespace Marvel\Providers;

use Illuminate\Support\ServiceProvider;
use Marvel\Services\Courier\ShippingServiceClient;
use Marvel\Services\DeliveryOptimizer\Cache\RedisQuoteCache;
use Marvel\Services\DeliveryOptimizer\Config\OptimizerConfig;
use Marvel\Services\DeliveryOptimizer\Contracts\CandidateProviderInterface;
use Marvel\Services\DeliveryOptimizer\Contracts\OptimizerConfigInterface;
use Marvel\Services\DeliveryOptimizer\Contracts\QuoteCacheInterface;
use Marvel\Services\DeliveryOptimizer\Contracts\ShippingQuoteClientInterface;
use Marvel\Services\DeliveryOptimizer\DeliveryOptimizerService;
use Marvel\Services\DeliveryOptimizer\PhaseA\CandidateProvider;
use Marvel\Services\DeliveryOptimizer\Quote\DefaultShippingQuoteClient;
use Marvel\Services\DeliveryOptimizer\Quote\EstimatedRateQuoter;
use Marvel\Services\DeliveryOptimizer\Quote\FirmQuoteClient;
use Marvel\Services\ItemAssignmentService;

/**
 * Wires the Delivery Optimizer's 4 DI interfaces (the extraction seam) to their default
 * in-monolith implementations. Everything is `bind` (fresh per resolution), NOT singleton,
 * because the candidate provider + optimizer hold per-cart warm-start memo — a singleton
 * would leak one cart's state into the next under a long-lived worker (Octane).
 */
final class DeliveryOptimizerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OptimizerConfigInterface::class, fn () => new OptimizerConfig());

        $this->app->bind(QuoteCacheInterface::class, fn () => new RedisQuoteCache());

        $this->app->bind(
            CandidateProviderInterface::class,
            fn () => new CandidateProvider(new ItemAssignmentService()),
        );

        $this->app->bind(ShippingQuoteClientInterface::class, function ($app) {
            $config = $app->make(OptimizerConfigInterface::class);
            return new DefaultShippingQuoteClient(
                $app->make(QuoteCacheInterface::class),
                new EstimatedRateQuoter(),
                new FirmQuoteClient(new ShippingServiceClient(), $config),
                $config,
            );
        });

        $this->app->bind(DeliveryOptimizerService::class, function ($app) {
            return new DeliveryOptimizerService(
                $app->make(CandidateProviderInterface::class),
                $app->make(ShippingQuoteClientInterface::class),
                $app->make(OptimizerConfigInterface::class),
            );
        });
    }
}
