<?php

namespace Marvel\Services\DeliveryOptimizer\Dto;

use Marvel\Services\DeliveryOptimizer\Support\Buckets;
use Marvel\Services\DeliveryOptimizer\Support\Rail;

/**
 * A request to price ONE consolidated leg. Carries what the estimate path needs
 * (baseCost/perKgCost/weight) and what the firm path needs (vendor + dest + items +
 * mode); the firm client resolves the real pickup ADDRESS from the Shop itself.
 *
 * The cache identity keys on the VENDOR (a quote is vendor-specific: their pickup
 * origin + their negotiated rate), the destination pincode, the bucketed weight, the
 * rail and COD — so two items from the same vendor/rail/dest/weight-bucket reuse one
 * cached quote. `eventId` is a deterministic idempotency token for firm retries.
 */
final class QuoteRequest
{
    /** @param CartItem[] $items */
    public function __construct(
        public readonly int $vendorId,
        public readonly string $rail,
        public readonly array $items,
        public readonly int $chargeableWeightG,
        public readonly float $baseCost,
        public readonly float $perKgCost,
        public readonly ?string $destPincode,
        public readonly bool $firm = false,
        public readonly bool $cod = false,
        public readonly float $codAmount = 0.0,
    ) {
    }

    public function legKey(): string
    {
        return $this->vendorId . '|' . $this->rail;
    }

    /** Bucketed quote-cache key. Collisions across nearby weights are intentional. */
    public function cacheKey(): string
    {
        return implode(':', [
            'do', 'q',
            $this->rail,
            'v' . $this->vendorId,
            $this->destPincode ?: '0',
            Buckets::weightBucket($this->chargeableWeightG),
            $this->cod ? '1' : '0',
        ]);
    }

    /** Deterministic idempotency token so retried firm batch calls de-dupe server-side. */
    public function eventId(): string
    {
        return md5($this->cacheKey());
    }

    public function shippingMode(): string
    {
        return Rail::toShippingMode($this->rail);
    }
}
