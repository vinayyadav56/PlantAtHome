<?php

namespace Tests\Unit\DeliveryOptimizer\Fakes;

use Marvel\Services\DeliveryOptimizer\Contracts\OptimizerConfigInterface;

/** In-memory OptimizerConfig for pure-unit tests (no Laravel container / config()). */
final class FakeConfig implements OptimizerConfigInterface
{
    public function __construct(private array $o = [])
    {
    }

    public function enabled(): bool
    {
        return $this->o['enabled'] ?? true;
    }

    public function topK(): int
    {
        return $this->o['topK'] ?? 5;
    }

    public function timeBudgetMs(): int
    {
        return $this->o['timeBudgetMs'] ?? 20;
    }

    public function instantTtl(): int
    {
        return $this->o['instantTtl'] ?? 60;
    }

    public function courierTtl(): int
    {
        return $this->o['courierTtl'] ?? 300;
    }

    public function slaPenaltyPerDay(): float
    {
        return $this->o['slaPenalty'] ?? 0.0;
    }

    public function targetSlaDays(): int
    {
        return $this->o['targetSla'] ?? 3;
    }

    public function baseFlatFee(): float
    {
        return $this->o['flatFee'] ?? 49.0;
    }

    public function freeDeliveryEnabled(): bool
    {
        return $this->o['freeEnabled'] ?? true;
    }

    public function freeDeliveryThreshold(): float
    {
        return $this->o['freeThreshold'] ?? 999.0;
    }

    public function firmQuotesAtBrowse(): bool
    {
        return $this->o['firmAtBrowse'] ?? false;
    }

    public function firmTimeoutMs(): int
    {
        return $this->o['firmTimeoutMs'] ?? 120;
    }

    public function defaultWeightG(): int
    {
        return $this->o['defaultWeightG'] ?? 500;
    }

    public function fullReoptEveryNEvents(): int
    {
        return $this->o['fullReoptEveryN'] ?? 8;
    }

    public function marginalReoptThreshold(): float
    {
        return $this->o['marginalReoptThreshold'] ?? 1.0;
    }
}
