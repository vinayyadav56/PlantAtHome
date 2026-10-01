<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Order;
use Marvel\Enums\PaymentGatewayType;
use Marvel\Enums\PaymentStatus;

/**
 * Backfill `orders.gateway_payment_id` for prepaid Razorpay orders.
 *
 * A gateway refund needs the Razorpay PAYMENT id to refund against:
 * RefundService::payout() reads `$order->gateway_payment_id` and throws
 * "Order … has no captured gateway payment to refund against." when it is blank.
 *
 * That column is written in exactly one place — AccountingPostingService::recordPaymentCaptured
 * — and that method no-ops while the accounting module is disabled. So on any environment that
 * ran with accounting off (production, to date), every order has it NULL and every gateway
 * refund is impossible regardless of credentials. This command fills the gap for the existing
 * order book so refunds can be issued against historical orders.
 *
 * What it does NOT do: post journals, change payment or order status, or touch money. It
 * resolves an id that Razorpay already holds and writes it to a column. Reconciling the books
 * is `accounting:post-pending`'s job, deliberately kept separate.
 *
 * The id is resolved the same way ReconcileRazorpayPendingCommand does it: payment_intents
 * stores the Razorpay ORDER id in payment_intent_info.payment_id, and
 * Razorpay::fetchCapturedPayment() exchanges that for the captured PAYMENT id. An order with
 * nothing captured at Razorpay is left alone — a blank column is recoverable, a wrong one
 * sends a refund to someone else's payment.
 *
 *   php artisan plantathome:backfill-gateway-payment-id --dry-run
 *   php artisan plantathome:backfill-gateway-payment-id --limit=100
 *   php artisan plantathome:backfill-gateway-payment-id --order=2026092890205948
 */
class BackfillGatewayPaymentIdCommand extends Command
{
    protected $signature = 'plantathome:backfill-gateway-payment-id
        {--dry-run : Report what would be written; change nothing}
        {--order= : Backfill a single tracking_number}
        {--limit=0 : Cap how many orders to process}';

    protected $description = 'Resolve and store the Razorpay payment id for prepaid orders so gateway refunds can run';

    public function handle(): int
    {
        if (!\Illuminate\Support\Facades\Schema::hasColumn('orders', 'gateway_payment_id')) {
            $this->error('orders.gateway_payment_id does not exist — run the accounting P3 migration first.');
            return self::FAILURE;
        }

        // Force the Payment facade onto the Razorpay driver: there is no HTTP request in a CLI
        // context, so the scoped binding would otherwise fall back to the default gateway.
        request()->merge(['payment_gateway' => 'razorpay']);

        $dry   = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $q = Order::whereNull('parent_id')
            ->where('payment_gateway', PaymentGatewayType::RAZORPAY)
            ->where('payment_status', PaymentStatus::SUCCESS)
            ->where(function ($w) {
                $w->whereNull('gateway_payment_id')->orWhere('gateway_payment_id', '');
            })
            ->orderByDesc('id');
        if ($this->option('order')) {
            $q->where('tracking_number', $this->option('order'));
        }
        if ($limit > 0) {
            $q->limit($limit);
        }
        $orders = $q->get();

        $stats = ['checked' => 0, 'filled' => 0, 'no_intent' => 0, 'not_captured' => 0, 'errors' => 0];
        $gateway = new \Marvel\Payments\Razorpay();

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
            $razorpayOrderId = $info['payment_id'] ?? null;   // the Razorpay ORDER id, despite the key name
            if (!$razorpayOrderId) {
                $stats['no_intent']++;
                $this->line(sprintf('  %-20s  no payment intent — skipped', $order->tracking_number));
                continue;
            }

            try {
                $captured = $gateway->fetchCapturedPayment((string) $razorpayOrderId);
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->warn(sprintf('  %-20s  lookup failed: %s', $order->tracking_number, $e->getMessage()));
                Log::warning('gateway payment id backfill lookup failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
                continue;
            }

            if (!$captured || empty($captured['id'])) {
                // Marked paid locally but nothing captured at Razorpay. Leave it blank and say so
                // loudly — this is a reconciliation discrepancy, not a backfill problem.
                $stats['not_captured']++;
                $this->warn(sprintf('  %-20s  local=paid but Razorpay has no capture — LEFT BLANK', $order->tracking_number));
                continue;
            }

            $stats['filled']++;
            $this->info(sprintf('  %-20s  → %s%s', $order->tracking_number, $captured['id'], $dry ? '  (WOULD write)' : ''));
            if (!$dry) {
                // saveQuietly: this is a data repair, not a business event. A model observer
                // firing here would stamp invoice numbers and write order_events for orders
                // whose status has not changed.
                $order->gateway_payment_id = (string) $captured['id'];
                $order->saveQuietly();
            }
        }

        $this->info(sprintf(
            '%schecked %d · filled %d · no-intent %d · not-captured %d · errors %d',
            $dry ? '[DRY-RUN] ' : '',
            $stats['checked'],
            $stats['filled'],
            $stats['no_intent'],
            $stats['not_captured'],
            $stats['errors']
        ));

        return self::SUCCESS;
    }
}
