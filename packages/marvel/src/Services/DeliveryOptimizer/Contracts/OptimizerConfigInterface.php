<?php

namespace Marvel\Services\DeliveryOptimizer\Contracts;

/**
 * All optimizer tunables in one place: config/deliveryoptimizer.php (static) overlaid
 * with settings.options (admin live-tunable, no deploy — mirrors the freeShipping keys
 * that CheckoutRepository::verify already reads so preview and charged totals agree).
 */
interface OptimizerConfigInterface
{
    public function enabled(): bool;

    public function topK(): int;

    /** Wall-clock budget (ms) for the 2-opt refinement loop. */
    public function timeBudgetMs(): int;

    public function instantTtl(): int;

    public function courierTtl(): int;

    public function slaPenaltyPerDay(): float;

    public function targetSlaDays(): int;

    public function baseFlatFee(): float;

    public function freeDeliveryEnabled(): bool;

    public function freeDeliveryThreshold(): float;

    /** When false (default), browse never calls the shipping-service synchronously. */
    public function firmQuotesAtBrowse(): bool;

    /** Tight per-call timeout (ms) for firm quotes on the hot path. */
    public function firmTimeoutMs(): int;

    public function defaultWeightG(): int;

    /** Incremental: force a full re-opt every N add/remove events. */
    public function fullReoptEveryNEvents(): int;

    /** Incremental: trigger a full re-opt when an item's marginal cost exceeds this. */
    public function marginalReoptThreshold(): float;
}
