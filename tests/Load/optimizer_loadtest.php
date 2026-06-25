<?php

/**
 * Delivery Optimizer load harness (CLI, no DB/Redis — measures the pure hot path).
 *
 *   php tests/Load/optimizer_loadtest.php [iterations]
 *
 * Part 1 — latency: random carts (1–50 lines, 1–3 candidate vendors each) through the real
 *          DeliveryOptimizerService on the BROWSE (estimate) path. Reports p50/p95/p99/max and
 *          gates p99 < 150ms (exit 1 on breach).
 * Part 2 — caching: repeated firm-at-browse quotes over a small bucketed key space, reporting
 *          the steady-state cache-hit-rate and firm-vs-estimate ratio.
 *
 * Uses the test fakes for candidates/quotes so it isolates the optimizer's own CPU + the
 * estimate arithmetic — exactly the latency floor the DESIGN targets.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Marvel\Services\DeliveryOptimizer\DeliveryOptimizerService;
use Marvel\Services\DeliveryOptimizer\Dto\Candidate;
use Marvel\Services\DeliveryOptimizer\Dto\CartItem;
use Marvel\Services\DeliveryOptimizer\Dto\QuoteRequest;
use Marvel\Services\DeliveryOptimizer\Dto\UserLocation;
use Marvel\Services\DeliveryOptimizer\Quote\DefaultShippingQuoteClient;
use Marvel\Services\DeliveryOptimizer\Quote\EstimatedRateQuoter;
use Marvel\Services\DeliveryOptimizer\Support\Rail;
use Tests\Unit\DeliveryOptimizer\Fakes\FakeCandidateProvider;
use Tests\Unit\DeliveryOptimizer\Fakes\FakeConfig;
use Tests\Unit\DeliveryOptimizer\Fakes\FakeFirmQuoteClient;
use Tests\Unit\DeliveryOptimizer\Fakes\FakeQuoteCache;
use Tests\Unit\DeliveryOptimizer\Fakes\FakeQuoteClient;

$iterations = (int) ($argv[1] ?? 3000);
mt_srand(424242);

$vendors = [];
for ($i = 1; $i <= 20; $i++) {
    $vendors[] = ['id' => $i, 'mode' => ($i % 3 === 0) ? 'courier' : 'local', 'base' => 40 + ($i % 5) * 10];
}

function percentile(array $sorted, float $p): float
{
    if (empty($sorted)) {
        return 0.0;
    }
    $idx = (int) ceil($p / 100 * count($sorted)) - 1;
    return $sorted[max(0, min(count($sorted) - 1, $idx))];
}

// ── Part 1: latency ────────────────────────────────────────────────────────────
$times = [];
$shipmentCounts = [];
for ($n = 0; $n < $iterations; $n++) {
    $lines = mt_rand(1, 50);
    $byItem = [];
    $cart = [];
    for ($l = 0; $l < $lines; $l++) {
        $pid = 1000 + $l;
        $cands = [];
        $numV = mt_rand(1, 3);
        for ($c = 0; $c < $numV; $c++) {
            $v = $vendors[mt_rand(0, 19)];
            $cands[] = new Candidate(
                "{$pid}:0",
                $v['id'],
                Rail::fromFulfillmentMode($v['mode']),
                $v['mode'],
                $v['mode'] === 'courier' ? 5 : 2,
                $v['mode'] === 'courier' ? 5 : 2,
                100.0,
                (float) $v['base'],
                5.0,
                1.0 - $c * 0.1,
                $c === 0,
            );
        }
        $byItem["{$pid}:0"] = $cands;
        $cart[] = new CartItem($pid, null, mt_rand(1, 3), mt_rand(200, 3000));
    }
    $service = new DeliveryOptimizerService(
        new FakeCandidateProvider($byItem),
        new FakeQuoteClient(),
        new FakeConfig(['timeBudgetMs' => 30]),
    );
    $t0 = hrtime(true);
    $res = $service->optimizeCart($cart, new UserLocation('Gurugram', '122001'));
    $times[] = (hrtime(true) - $t0) / 1e6;
    $shipmentCounts[] = count($res->shipments);
}
sort($times);

$p50 = percentile($times, 50);
$p95 = percentile($times, 95);
$p99 = percentile($times, 99);
$max = end($times);
$mean = array_sum($times) / count($times);

echo "=== Delivery Optimizer load harness ===\n";
echo "iterations: {$iterations}  (carts 1–50 lines, 1–3 vendors/line)\n\n";
echo "Latency (browse/estimate hot path), ms:\n";
printf("  p50=%.3f  p95=%.3f  p99=%.3f  max=%.3f  mean=%.3f\n", $p50, $p95, $p99, $max, $mean);
printf("  avg shipments/cart: %.2f\n\n", array_sum($shipmentCounts) / count($shipmentCounts));

// ── Part 2: cache hit-rate (firm-at-browse over a small bucket space) ───────────
$cache = new FakeQuoteCache();
$firm = new FakeFirmQuoteClient('fee', 99.0);
$client = new DefaultShippingQuoteClient($cache, new EstimatedRateQuoter(), $firm, new FakeConfig(['firmAtBrowse' => true]));

$quoteCalls = 20000;
for ($q = 0; $q < $quoteCalls; $q++) {
    // Small key space: 20 vendors × 3 dest buckets × 4 weight buckets → high reuse.
    $vendorId = mt_rand(1, 20);
    $dest = (string) (122000 + mt_rand(0, 2));
    $weight = [500, 1000, 2000, 5000][mt_rand(0, 3)];
    $req = new QuoteRequest($vendorId, Rail::COURIER, [], $weight, 80.0, 5.0, $dest, true);
    $client->quote($req);
}
$firmCalls = $firm->calls; // each firm batch call = one cache miss
$hitRate = $quoteCalls > 0 ? (1 - $firmCalls / $quoteCalls) * 100 : 0;

echo "Caching (firm-at-browse, small bucket space):\n";
printf("  quotes=%d  firm(carrier) calls=%d  cache-hit-rate=%.1f%%\n\n", $quoteCalls, $firmCalls, $hitRate);

$gate = 150.0;
if ($p99 < $gate) {
    printf("GATE PASS: p99 %.3fms < %.0fms\n", $p99, $gate);
    exit(0);
}
printf("GATE FAIL: p99 %.3fms >= %.0fms\n", $p99, $gate);
exit(1);
