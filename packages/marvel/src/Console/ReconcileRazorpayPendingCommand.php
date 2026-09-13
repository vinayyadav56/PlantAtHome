<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Order;
use Marvel\Enums\PaymentGatewayType;
use Marvel\Enums\PaymentStatus;
use Marvel\Facades\Payment;
use Marvel\Traits\PaymentStatusManagerWithOrderTrait;

/**
 * Reconcile Razorpay orders that were PAID at the gateway but never confirmed
 * in our DB — the "captured on Razorpay, stuck payment-pending" case.
 *
 * The storefront confirms a payment with a single client call
 * (POST /orders/payment); if that call is lost (tab closed, network blip, the
 * modal-unmount bug this ships alongside), Razorpay keeps the money and the
 * order sits payment-pending forever — and orders:cancel-stale-unpaid would
 * eventually CANCEL a genuinely paid order. This is the server-side safety net
 * (the Razorpay webhook is the real-time one).
 *
 * For every RAZORPAY order still payment-pending with a stored payment intent,
 * it asks Razorpay for the intent's status and, only when Razorpay itself says
 * "paid", runs the exact same paymentSuccess() path the live endpoint uses
 * (status flip + child orders + PaymentSuccess event → notifications + auto-book).
 * Verify-only: it never marks anything paid that Razorpay hasn't.
 *
 *   php artisan plantathome:reconcile-razorpay-pending --dry-run
 *   php artisan plantathome:reconcile-razorpay-pending --order=2026091386313644
 *   php artisan plantathome:reconcile-razorpay-pending --limit=50
 *   php artisan plantathome:reconcile-razorpay-pending
 */
class ReconcileRazorpayPendingCommand extends Command
{
    use PaymentStatusManagerWithOrderTrait;

    protected $signature = 'plantathome:reconcile-razorpay-pending
        {--dry-run : Report what Razorpay says; write nothing}
        {--order= : Reconcile a single tracking_number}
        {--limit=0 : Cap how many pending orders to check}';

    protected $description = 'Confirm Razorpay orders that were paid at the gateway but left payment-pending in our DB';

    public function handle(): int
    {
        // Force the Payment facade to resolve the Razorpay driver — no HTTP
        // request in a CLI context, so the scoped binding would otherwise fall
        // back to the default gateway.
        request()->merge(['payment_gateway' => 'razorpay']);

        $dry = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $q = Order::whereNull('parent_id')
            ->where('payment_gateway', PaymentGatewayType::RAZORPAY)
            ->whereIn('payment_status', [PaymentStatus::PENDING, PaymentStatus::PROCESSING])
            ->orderByDesc('id');
        if ($this->option('order')) {
            $q->where('tracking_number', $this->option('order'));
        }
        if ($limit > 0) {
            $q->limit($limit);
        }
        $orders = $q->get();

        $stats = ['checked' => 0, 'paid' => 0, 'still_unpaid' => 0, 'no_intent' => 0, 'errors' => 0];

        foreach ($orders as $order) {
            $stats['checked']++;

            $intent = DB::table('payment_intents')
                ->where('order_id', $order->id)
                ->whereNull('deleted_at')
                ->orderByDesc('id')
                ->first();
            $info = $intent && $intent->payment_intent_info
                ? json_decode($intent->payment_intent_info, true)
                : null;
            $paymentId = $info['payment_id'] ?? null;
            if (! $paymentId) {
                $stats['no_intent']++;
                $this->line(sprintf('  %-20s  no payment intent — skipped', $order->tracking_number));
                continue;
            }

            try {
                $status = strtolower((string) Payment::verify($paymentId));
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->warn(sprintf('  %-20s  verify failed: %s', $order->tracking_number, $e->getMessage()));
                continue;
            }

            if ($status === 'paid') {
                $stats['paid']++;
                $this->info(sprintf('  %-20s  Razorpay=paid  → %s', $order->tracking_number, $dry ? 'WOULD confirm' : 'confirming'));
                if (! $dry) {
                    try {
                        $this->paymentSuccess($order);
                    } catch (\Throwable $e) {
                        $stats['errors']++;
                        Log::warning('reconcile paymentSuccess failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                        $this->warn("    confirm failed: {$e->getMessage()}");
                    }
                }
            } else {
                $stats['still_unpaid']++;
                $this->line(sprintf('  %-20s  Razorpay=%s — left as is', $order->tracking_number, $status ?: 'unknown'));
            }
        }

        $this->info(sprintf(
            '%schecked %d · paid→confirmed %d · still-unpaid %d · no-intent %d · errors %d',
            $dry ? '[DRY-RUN] ' : '', $stats['checked'], $stats['paid'], $stats['still_unpaid'], $stats['no_intent'], $stats['errors']
        ));

        return self::SUCCESS;
    }
}
