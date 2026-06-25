<?php

namespace Marvel\Services\DeliveryOptimizer\Config;

use Marvel\Database\Models\Settings;
use Marvel\Services\DeliveryOptimizer\Contracts\OptimizerConfigInterface;

/**
 * config/deliveryoptimizer.php overlaid with settings.options. The flat fee and
 * free-delivery threshold deliberately read the SAME settings keys CheckoutRepository::verify
 * uses (freeShipping / freeShippingAmount), so the optimizer preview and the charged total
 * never disagree. Settings are read once and memoised for the request.
 */
final class OptimizerConfig implements OptimizerConfigInterface
{
    private ?array $options = null;
    private bool $loaded = false;

    public function enabled(): bool
    {
        return (bool) config('deliveryoptimizer.enabled', false);
    }

    public function topK(): int
    {
        return max(1, (int) config('deliveryoptimizer.top_k', 5));
    }

    public function timeBudgetMs(): int
    {
        return max(0, (int) config('deliveryoptimizer.time_budget_ms', 30));
    }

    public function instantTtl(): int
    {
        return max(1, (int) config('deliveryoptimizer.ttl.instant', 60));
    }

    public function courierTtl(): int
    {
        return max(1, (int) config('deliveryoptimizer.ttl.courier', 300));
    }

    public function slaPenaltyPerDay(): float
    {
        return (float) config('deliveryoptimizer.sla_penalty_per_day', 5.0);
    }

    public function targetSlaDays(): int
    {
        return max(1, (int) config('deliveryoptimizer.target_sla_days', 3));
    }

    public function baseFlatFee(): float
    {
        return (float) config('deliveryoptimizer.flat_fee', 49.0);
    }

    public function freeDeliveryEnabled(): bool
    {
        $opt = $this->option('freeShipping');
        if ($opt !== null) {
            return (bool) $opt;
        }
        return (bool) config('deliveryoptimizer.free_delivery_enabled', true);
    }

    public function freeDeliveryThreshold(): float
    {
        // Mirror CheckoutRepository::verify line 95 EXACTLY:
        //   !empty(freeShipping) && freeShippingAmount <= $amount ? free : charge
        // When freeShipping is on but freeShippingAmount is unset/null, PHP evaluates
        // `null <= $amount` as TRUE → the customer is charged ZERO shipping at any cart value.
        // So the effective threshold is 0 (free at any amount), NOT the config default.
        $freeShipping = $this->option('freeShipping');
        if ($freeShipping !== null && (bool) $freeShipping) {
            $amount = $this->option('freeShippingAmount');
            return $amount !== null ? (float) $amount : 0.0;
        }
        return (float) config('deliveryoptimizer.free_delivery_threshold', 999.0);
    }

    public function firmQuotesAtBrowse(): bool
    {
        return (bool) config('deliveryoptimizer.firm_quotes_at_browse', false);
    }

    public function firmTimeoutMs(): int
    {
        return max(1, (int) config('deliveryoptimizer.firm_timeout_ms', 120));
    }

    public function defaultWeightG(): int
    {
        return max(1, (int) config('deliveryoptimizer.default_weight_g', 500));
    }

    public function fullReoptEveryNEvents(): int
    {
        return (int) config('deliveryoptimizer.full_reopt_every_n_events', 8);
    }

    public function marginalReoptThreshold(): float
    {
        return (float) config('deliveryoptimizer.marginal_reopt_threshold', 1.0);
    }

    /** settings.options[$key] or null when unset/unavailable (fail-safe). */
    private function option(string $key)
    {
        if (!$this->loaded) {
            $this->loaded = true;
            try {
                $settings = Settings::first();
                $this->options = is_array($settings?->options) ? $settings->options : [];
            } catch (\Throwable $e) {
                $this->options = [];
            }
        }
        return $this->options[$key] ?? null;
    }
}
