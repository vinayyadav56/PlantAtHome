<?php

namespace App\Modules\Analytics\Application;

use App\Modules\Analytics\Infrastructure\Models\Counter;
use App\Shared\Events\IntegrationMessage;
use Illuminate\Support\Facades\DB;

/**
 * Read-model aggregation (Section 3). Consumes order events off the request path
 * and maintains rollup counters; derives KPIs (AOV, per-vendor revenue) on read.
 * Idempotency is handled by the outbox ledger (exactly-once per subscriber).
 */
class AnalyticsService
{
    public function recordOrderPlaced(IntegrationMessage $message): void
    {
        $payload = $message->payload;
        $grandMinor = (int) ($payload['totals']['grand_total']['amount_minor'] ?? 0);

        DB::transaction(function () use ($payload, $grandMinor) {
            $this->bump('orders', 'global', 0, 1);
            $this->bump('revenue', 'global', $grandMinor / 100, 0);

            foreach ($payload['sub_orders'] ?? [] as $sub) {
                $nurseryDim = 'nursery:'.($sub['nursery_id'] ?? 'unknown');
                $this->bump('orders', $nurseryDim, 0, 1);
                $this->bump('revenue', $nurseryDim, ((int) ($sub['subtotal_minor'] ?? 0)) / 100, 0);
            }
        });
    }

    /** @return array{orders:int, revenue:float, aov:float, per_vendor:array} */
    public function kpis(): array
    {
        $orders = (int) (Counter::where('metric', 'orders')->where('dimension', 'global')->value('value_count') ?? 0);
        $revenue = (float) (Counter::where('metric', 'revenue')->where('dimension', 'global')->value('value_sum') ?? 0);

        $perVendor = [];
        foreach (Counter::where('dimension', 'like', 'nursery:%')->get()->groupBy('dimension') as $dim => $rows) {
            $nurseryId = substr($dim, strlen('nursery:'));
            $perVendor[] = [
                'nursery_id' => $nurseryId,
                'orders'     => (int) ($rows->firstWhere('metric', 'orders')?->value_count ?? 0),
                'revenue'    => (float) ($rows->firstWhere('metric', 'revenue')?->value_sum ?? 0),
            ];
        }

        return [
            'orders'     => $orders,
            'revenue'    => round($revenue, 2),
            'aov'        => $orders > 0 ? round($revenue / $orders, 2) : 0.0,
            'per_vendor' => $perVendor,
        ];
    }

    private function bump(string $metric, string $dimension, float $sum, int $count): void
    {
        $counter = Counter::firstOrCreate(['metric' => $metric, 'dimension' => $dimension]);
        $counter->value_sum = (float) $counter->value_sum + $sum;
        $counter->value_count += $count;
        $counter->save();
    }
}
