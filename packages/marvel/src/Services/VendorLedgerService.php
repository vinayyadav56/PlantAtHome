<?php

namespace Marvel\Services;

use Carbon\Carbon;
use Marvel\Database\Models\Balance;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\VendorLedgerEntry;

/**
 * Writes the vendor money ledger. On a child order reaching COMPLETED we record ONE
 * `sale` entry per (child) order capturing the full breakdown; on refund/cancel we
 * record a `refund_reversal`. Earnings become settle-eligible at delivered + N days
 * (the T+N hold). This runs in PARALLEL with the legacy order-level `balances` credit
 * (it never touches `balances`) and is gated by settings.options.settlement.enabled so
 * it's a no-op until the admin turns it on (then both run for reconciliation).
 *
 * Money per (child) order:
 *   product_value  = order.amount (line subtotal, excl tax/delivery/discount)
 *   commission     = product_value × balances.admin_commission_rate%
 *   platform_fee   = settings.options.fees.platform_percent% (+ platform_flat)
 *   pg_fee         = settings.options.fees.pg_percent% of product_value (vendor revenue only)
 *   vendor_earning = product_value − commission − platform_fee − pg_fee
 */
class VendorLedgerService
{
    private array $fees;
    private bool $enabled;
    private int $holdDays;

    public function __construct()
    {
        $options = (array) (Settings::getData()->options ?? []);
        $settlement = (array) ($options['settlement'] ?? []);
        $this->fees = (array) ($options['fees'] ?? []);
        // Env override wins; else the admin settings flag; default OFF.
        $envFlag = env('MARKETPLACE_LEDGER');
        $this->enabled = $envFlag !== null
            ? filter_var($envFlag, FILTER_VALIDATE_BOOLEAN)
            : (bool) ($settlement['enabled'] ?? false);
        $this->holdDays = max(0, (int) ($settlement['hold_days'] ?? 7));
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /** Record a vendor sale for a completed child order (idempotent per order). */
    public function recordSale(Order $order): void
    {
        if (!$this->enabled || !$order->shop_id) {
            return;
        }
        if (VendorLedgerEntry::where('order_id', $order->id)->where('entry_type', 'sale')->exists()) {
            return; // already recorded
        }

        $shopId = (int) $order->shop_id;
        $rate = (float) (Balance::where('shop_id', $shopId)->value('admin_commission_rate') ?? 0);

        $productValue = round((float) ($order->amount ?? 0), 2);
        $commission   = round($productValue * $rate / 100, 2);
        $platformFee  = $this->platformFee($productValue);
        $pgFee        = $this->pgFee($productValue);
        $vendorEarning = round($productValue - $commission - $platformFee - $pgFee, 2);

        $now = Carbon::now();
        VendorLedgerEntry::create([
            'shop_id'           => $shopId,
            'order_id'          => $order->id,
            'entry_type'        => 'sale',
            'amount'            => $vendorEarning,
            'product_value'     => $productValue,
            'commission_amount' => $commission,
            'platform_fee'      => $platformFee,
            'pg_fee'            => $pgFee,
            'shipping_revenue'  => 0,
            'source'            => 'delivery',
            'status'            => 'pending',
            'available_at'      => $now->copy()->addDays($this->holdDays),
            'earned_at'         => $now,
        ]);
    }

    /**
     * Reverse a previously-recorded sale (refund / cancel), idempotent.
     * - If the sale hasn't settled yet → CANCEL it (mark both reversed, not settle-eligible),
     *   so the refunded earning simply never pays out and never nets against unrelated orders.
     * - If the sale was already settled/paid → record a settle-eligible clawback that nets
     *   against the vendor's future earnings (a genuine, bounded debt).
     */
    public function reverseSale(Order $order): void
    {
        if (!$this->enabled) {
            return;
        }
        $sale = VendorLedgerEntry::where('order_id', $order->id)->where('entry_type', 'sale')->first();
        if (!$sale) {
            return;
        }
        if (VendorLedgerEntry::where('order_id', $order->id)->where('entry_type', 'refund_reversal')->exists()) {
            return; // already reversed
        }

        $now = Carbon::now();
        $alreadySettled = $sale->status !== 'pending'; // settled (and possibly paid)

        $entry = [
            'shop_id'           => $sale->shop_id,
            'order_id'          => $order->id,
            'entry_type'        => 'refund_reversal',
            'amount'            => -1 * (float) $sale->amount,
            'product_value'     => -1 * (float) $sale->product_value,
            'commission_amount' => -1 * (float) $sale->commission_amount,
            'platform_fee'      => -1 * (float) $sale->platform_fee,
            'pg_fee'            => -1 * (float) $sale->pg_fee,
            'shipping_revenue'  => -1 * (float) $sale->shipping_revenue,
            'source'            => 'refund',
            'earned_at'         => $now,
            'note'              => 'Reversal of ledger entry #' . $sale->id,
        ];

        if ($alreadySettled) {
            // Clawback: nets against the vendor's future settlements.
            $entry['status'] = 'pending';
            $entry['available_at'] = $now;
            VendorLedgerEntry::create($entry);
        } else {
            // Sale not yet paid → cancel it outright; neither row is ever settled.
            $entry['status'] = 'reversed';
            $entry['available_at'] = null;
            VendorLedgerEntry::create($entry);
            $sale->update(['status' => 'reversed']);
        }
    }

    private function platformFee(float $value): float
    {
        $percent = (float) ($this->fees['platform_percent'] ?? 0);
        $flat    = (float) ($this->fees['platform_flat'] ?? 0);
        return round($value * $percent / 100 + $flat, 2);
    }

    /**
     * PG fee charged to the vendor, on the vendor's OWN revenue (product_value) only —
     * never on the tax or delivery the vendor doesn't receive. (If a future policy wants
     * the fee on the full charged amount, make that explicit; today it's vendor revenue.)
     */
    private function pgFee(float $value): float
    {
        $percent = (float) ($this->fees['pg_percent'] ?? 0);
        if ($percent <= 0) {
            return 0.0;
        }
        return round($value * $percent / 100, 2);
    }
}
