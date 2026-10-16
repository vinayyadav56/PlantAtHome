<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Order;
use Marvel\Database\Repositories\OrderRepository;
use Marvel\Enums\OrderStatus;
use Marvel\Enums\PaymentGatewayType;
use Marvel\Enums\PaymentStatus;

/**
 * Cancel stale unpaid PREPAID orders so their stock and coupon slots return.
 *
 * Why: order creation deducts stock and consumes the coupon inside the order
 * transaction. When the customer abandons payment (or the gateway webhook only
 * reports `payment.failed`, which deliberately keeps the order payable for a
 * retry), nothing ever times the order out — the stock and the coupon slot
 * leak forever. This sweep closes that loop with OrderRepository::closeUnpaidOrder,
 * the same close as the customer's "No" on the payment-failed prompt:
 * OrderCancelled ⇒ ProductInventoryRestore (idempotent via orders.inventory_restored,
 * parent+children aware), CouponRepository::release (floors at 0, keyed on the usage
 * ledger) and the wallet points the attempt took. A never-placed attempt is closed
 * silently — nobody was told it existed.
 *
 * COD/CASH orders are exempt (payment happens at the door), and so are
 * full-wallet orders (paid instantly at creation).
 */
class CancelStaleUnpaidOrdersCommand extends Command
{
    protected $signature = 'orders:cancel-stale-unpaid
        {--hours= : Age threshold (default config shop.stale_unpaid_order_hours, 24)}
        {--limit=200 : Max orders per run (the sweep re-runs every 15 min)}
        {--dry-run : Report matches without cancelling}';

    protected $description = 'Cancel prepaid orders left unpaid beyond the threshold, restoring stock and coupon slots';

    public function handle(): int
    {
        $hours = (int) ($this->option('hours') ?: config('shop.stale_unpaid_order_hours', 24));
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $stale = Order::whereNull('parent_id')
            ->where('created_at', '<', now()->subHours($hours))
            // Strictly PENDING, plus a never-placed attempt the webhook marked FAILED (it kept its
            // stock, coupon and wallet points forever before). PROCESSING may still settle and is
            // reconciled by plantathome:reconcile-razorpay-pending — cancelling it could void a
            // payment that captures moments later.
            ->where(fn ($q) => $q->where('payment_status', PaymentStatus::PENDING)
                ->orWhere(fn ($f) => $f->where('payment_status', PaymentStatus::FAILED)->whereNull('placed_at')))
            ->whereIn('order_status', [OrderStatus::PENDING, OrderStatus::PROCESSING])
            // COD is stored as any of CASH_ON_DELIVERY/COD/CASH depending on the
            // client (see PaymentGatewayType::isCashOnDelivery) — exclude all
            // three, plus wallet (paid instantly at creation).
            ->whereNotIn('payment_gateway', [
                PaymentGatewayType::CASH,
                PaymentGatewayType::CASH_ON_DELIVERY,
                'COD',
                PaymentGatewayType::FULL_WALLET_PAYMENT,
            ])
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($stale->isEmpty()) {
            $this->info('No stale unpaid orders.');
            return self::SUCCESS;
        }

        $cancelled = 0;
        $repository = app(OrderRepository::class);
        foreach ($stale as $order) {
            if ($dryRun) {
                $this->line("would cancel: #{$order->tracking_number} ({$order->payment_gateway}, created {$order->created_at})");
                continue;
            }
            try {
                // The same close as the customer's "No" on the payment-failed prompt: cancels the
                // parent and children and gives back stock, the coupon slot and wallet points.
                if ($repository->closeUnpaidOrder($order, 'system:stale-cancel')) {
                    $cancelled++;
                }
            } catch (\Throwable $e) {
                Log::warning('stale-unpaid sweep: cancel failed', [
                    'order_id' => $order->id,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        $msg = $dryRun
            ? sprintf('%d stale unpaid orders matched (dry run).', $stale->count())
            : sprintf('Cancelled %d/%d stale unpaid orders (older than %dh).', $cancelled, $stale->count(), $hours);
        $this->info($msg);
        Log::info('orders:cancel-stale-unpaid', ['matched' => $stale->count(), 'cancelled' => $cancelled, 'hours' => $hours]);

        return self::SUCCESS;
    }
}
