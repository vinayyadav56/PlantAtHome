<?php

namespace Tests\Unit\DeliveryOptimizer;

use Marvel\Services\DeliveryOptimizer\Support\Buckets;
use PHPUnit\Framework\TestCase;

final class BucketsTest extends TestCase
{
    public function test_weight_snaps_up_to_carrier_slabs(): void
    {
        $this->assertSame(500, Buckets::weightBucket(1));
        $this->assertSame(500, Buckets::weightBucket(500));
        $this->assertSame(1000, Buckets::weightBucket(501));
        $this->assertSame(1000, Buckets::weightBucket(1000));
        $this->assertSame(2000, Buckets::weightBucket(1500));
        $this->assertSame(5000, Buckets::weightBucket(2001));
        $this->assertSame(20000, Buckets::weightBucket(12000));
    }

    public function test_above_largest_slab_rounds_up_to_250g_step(): void
    {
        // 25000 > 20000 slab → ceil(25000/250)*250
        $this->assertSame(25000, Buckets::weightBucket(25000));
        $this->assertSame(25000, Buckets::weightBucket(24801));
    }

    public function test_nearby_weights_collide_for_cache_reuse(): void
    {
        $this->assertSame(Buckets::weightBucket(740), Buckets::weightBucket(900));
        $this->assertSame(Buckets::weightBucket(1001), Buckets::weightBucket(1999));
    }

    public function test_dims_bucket_uses_volumetric_weight(): void
    {
        // 10×10×10 cm = 1000 / 5000 = 0.2 kg = 200 g → slab 500
        $this->assertSame(500, Buckets::dimsBucket(10, 10, 10));
        // 50×40×30 = 60000 / 5000 = 12 kg → slab 20000
        $this->assertSame(20000, Buckets::dimsBucket(50, 40, 30));
        // Missing dimension → 0 (caller falls back to actual weight)
        $this->assertSame(0, Buckets::dimsBucket(10, 10, null));
    }

    public function test_chargeable_weight_takes_max_of_actual_and_volumetric(): void
    {
        $this->assertSame(2000, Buckets::chargeableWeightBucket(600, 1200));
        $this->assertSame(1000, Buckets::chargeableWeightBucket(900, 0));
    }
}
