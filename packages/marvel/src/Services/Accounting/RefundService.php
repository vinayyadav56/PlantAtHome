<?php

namespace Marvel\Services\Accounting;

use App\Shared\Domain\ValueObject\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Accounting\AccountingAuditLog;
use Marvel\Database\Models\Accounting\AccountingSequence;
use Marvel\Database\Models\Accounting\JournalEntry;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderEvent;
use Marvel\Database\Models\OrderItem;
use Marvel\Database\Models\Refund;
use Marvel\Services\VendorLedgerService;

/**
 * Refund accounting (spec §26, §48): every refund is sliced from the ORDER'S IMMUTABLE
 * SNAPSHOT — per line: taxable value, CGST/SGST|IGST, platform discount share, vendor
 * payable — never from today's product/tax/commission configuration.
 *
 *   post():   recognised order → DR Product Sales / DR GST payable / (DR Delivery Revenue, full)
 *                                 / CR Discounts Given (platform share) / CR Customer Refund Payable
 *                                 + DR Vendor Payables[shop] / CR Vendor Settlement Cost per line
 *                                 + per-line vendor sub-ledger reversal + a GST credit note
 *             not yet recognised → DR Customer Advances / CR Customer Refund Payable
 *   payout(): wallet  DR Refund Payable / CR Wallet Liability
 *             gateway DR Refund Payable / CR Gateway Receivable (after the Razorpay refund)
 *             manual  DR Refund Payable / CR Bank
 * Both idempotent by journal source_key; no-ops while accounting is disabled.
 */
class RefundService
{
    /** @var callable(string $paymentId, int $paise, string $receipt): array{id:string, amount:int, status:string} */
    private $gatewayRefunder;

    public function __construct(private readonly AccountingConfig $config, private readonly JournalService $journal, ?callable $gatewayRefunder = null)
    {
        $this->gatewayRefunder = $gatewayRefunder ?: fn (string $pid, int $paise, string $receipt) => (new \Marvel\Payment\Razorpay())->refund($pid, $paise, $receipt);
    }

    public static function make(): self
    {
        return new self(new AccountingConfig(), new JournalService());
    }

    public function withGatewayRefunder(callable $fn): self
    {
        $this->gatewayRefunder = $fn;
        return $this;
    }

    /**
     * Compute what a refund is made of. Returns
     *  ['amount' => Money, 'lines' => [item_id => [quantity, taxable, tax, cgst, sgst, igst, discount, vendor_share, shop_id, amount]],
     *   'delivery' => ['taxable' => Money, 'cgst','sgst','igst'], 'platform_discount' => Money]
     * Refuses to exceed what the customer paid minus refunds already approved.
     */
    public function slices(Order $order, string $scope, array $items = [], ?string $requestedAmount = null, ?int $excludeRefundId = null): array
    {
        $lines = OrderItem::where('order_id', $order->id)->get()->keyBy('id');
        $paid = MoneyBridge::toMoney($order->paid_total);
        $alreadyRefunded = MoneyBridge::toMoney((string) Refund::where('order_id', $order->id)->whereNull('shop_id')->where('status', 'approved')->when($excludeRefundId, fn ($q) => $q->where('id', '!=', $excludeRefundId))->sum('amount'));
        $refundable = $paid->subtract($alreadyRefunded);
        $refundedQty = $this->refundedQuantities($order, $excludeRefundId);

        $out = ['amount' => MoneyBridge::zero(), 'lines' => [], 'delivery' => null, 'platform_discount' => MoneyBridge::zero()];
        $slice = function (OrderItem $it, int $qty) use (&$out) {
            $q = max(1, (int) $it->order_quantity);
            $ratio = $qty / $q;
            $taxable = MoneyBridge::toMoney($it->taxable_value ?? $it->subtotal ?? 0)->multiply($ratio);
            $cg = MoneyBridge::toMoney($it->cgst_amount ?? 0)->multiply($ratio);
            $sg = MoneyBridge::toMoney($it->sgst_amount ?? 0)->multiply($ratio);
            $ig = MoneyBridge::toMoney($it->igst_amount ?? 0)->multiply($ratio);
            $tax = $cg->add($sg)->add($ig);
            $disc = ($it->discount_funded_by ?? 'platform') !== 'vendor' ? MoneyBridge::toMoney($it->discount_amount ?? 0)->multiply($ratio) : MoneyBridge::zero();
            $vendor = MoneyBridge::toMoney($it->vendor_payable_snapshot ?? 0)->multiply($ratio);
            $amount = $taxable->add($tax)->subtract($disc); // what the customer paid for this slice
            $out['lines'][$it->id] = ['quantity' => $qty, 'taxable' => $taxable, 'tax' => $tax, 'cgst' => $cg, 'sgst' => $sg, 'igst' => $ig, 'discount' => $disc, 'vendor_share' => $vendor, 'shop_id' => $it->assigned_shop_id, 'amount' => $amount];
            $out['amount'] = $out['amount']->add($amount);
            $out['platform_discount'] = $out['platform_discount']->add($disc);
        };

        if ($scope === 'items') {
            if (!$items) {
                throw new \InvalidArgumentException('Item refund needs at least one line.');
            }
            foreach ($items as $req) {
                $it = $lines[(int) ($req['order_item_id'] ?? 0)] ?? null;
                if (!$it) {
                    throw new \InvalidArgumentException('Line ' . ($req['order_item_id'] ?? '?') . ' does not belong to this order.');
                }
                $remaining = (int) $it->order_quantity - ($refundedQty[$it->id] ?? 0);
                $qty = min((int) ($req['quantity'] ?? 1), $remaining);
                if ($qty <= 0) {
                    throw new \InvalidArgumentException('Line ' . $it->id . ' has already been fully refunded.');
                }
                $slice($it, $qty);
            }
        } elseif ($scope === 'partial') {
            $want = MoneyBridge::toMoney($requestedAmount ?? '0');
            if ($want->isZero() || $want->isNegative()) {
                throw new \InvalidArgumentException('A partial refund needs a positive amount.');
            }
            // Allocate the amount across lines by what the customer paid for each (largest remainder),
            // then split each slice into taxable/tax by the line's own snapshot proportions.
            $weights = [];
            foreach ($lines as $it) {
                $gross = MoneyBridge::toMoney($it->taxable_value ?? $it->subtotal ?? 0)->add(MoneyBridge::toMoney($it->tax_amount ?? 0))->subtract(MoneyBridge::toMoney($it->discount_amount ?? 0));
                $weights[$it->id] = $gross->amountMinor();
            }
            foreach (MoneyBridge::allocate($want, $weights) as $itemId => $part) {
                if ($part->isZero()) {
                    continue;
                }
                $it = $lines[$itemId];
                $gross = max(1, $weights[$itemId]);
                $ratio = $part->amountMinor() / $gross; // fraction of the line's paid price
                $taxable = MoneyBridge::toMoney($it->taxable_value ?? $it->subtotal ?? 0)->multiply($ratio);
                $cg = MoneyBridge::toMoney($it->cgst_amount ?? 0)->multiply($ratio);
                $sg = MoneyBridge::toMoney($it->sgst_amount ?? 0)->multiply($ratio);
                $ig = MoneyBridge::toMoney($it->igst_amount ?? 0)->multiply($ratio);
                $disc = ($it->discount_funded_by ?? 'platform') !== 'vendor' ? MoneyBridge::toMoney($it->discount_amount ?? 0)->multiply($ratio) : MoneyBridge::zero();
                $vendor = MoneyBridge::toMoney($it->vendor_payable_snapshot ?? 0)->multiply($ratio);
                $out['lines'][$it->id] = ['quantity' => 0, 'taxable' => $taxable, 'tax' => $cg->add($sg)->add($ig), 'cgst' => $cg, 'sgst' => $sg, 'igst' => $ig, 'discount' => $disc, 'vendor_share' => $vendor, 'shop_id' => $it->assigned_shop_id, 'amount' => $part];
                $out['amount'] = $out['amount']->add($part);
                $out['platform_discount'] = $out['platform_discount']->add($disc);
            }
        } else { // full: every remaining unit + delivery; the customer gets back exactly what they paid
            foreach ($lines as $it) {
                $remaining = (int) $it->order_quantity - ($refundedQty[$it->id] ?? 0);
                if ($remaining > 0) {
                    $slice($it, $remaining);
                }
            }
            $lineCg = MoneyBridge::sum(array_map(fn ($l) => $l['cgst'], $out['lines']));
            $lineSg = MoneyBridge::sum(array_map(fn ($l) => $l['sgst'], $out['lines']));
            $lineIg = MoneyBridge::sum(array_map(fn ($l) => $l['igst'], $out['lines']));
            $dTax = MoneyBridge::toMoney($order->delivery_tax_amount ?? 0);
            $dTaxable = $order->delivery_taxable !== null ? MoneyBridge::toMoney($order->delivery_taxable) : MoneyBridge::toMoney($order->delivery_fee ?? 0)->subtract($dTax);
            $out['delivery'] = [
                'taxable' => $dTaxable,
                'cgst' => $this->floor(MoneyBridge::toMoney($order->cgst_amount ?? 0)->subtract($lineCg)),
                'sgst' => $this->floor(MoneyBridge::toMoney($order->sgst_amount ?? 0)->subtract($lineSg)),
                'igst' => $this->floor(MoneyBridge::toMoney($order->igst_amount ?? 0)->subtract($lineIg)),
            ];
            $out['amount'] = $refundable; // exactly what is still refundable; the journal reconciles the paise residual
        }

        if ($out['amount']->amountMinor() > $refundable->amountMinor()) {
            throw new \InvalidArgumentException('Refund ' . $out['amount']->toDecimal() . ' exceeds the refundable ' . $refundable->toDecimal() . '.');
        }
        return $out;
    }

    /** Post the refund journal + vendor reversal + credit note. Idempotent (REFUND_POSTED:{id}). */
    public function post(Refund $refund, ?string $actor = null): ?JournalEntry
    {
        if (!$this->config->enabled()) {
            return null;
        }
        $key = 'REFUND_POSTED:' . $refund->id;
        if ($existing = JournalEntry::where('source_key', $key)->first()) {
            return $existing;
        }
        $order = Order::findOrFail($refund->order_id);
        $scope = $refund->scope ?: 'full';
        $items = [];
        if ($scope === 'items') {
            foreach (DB::table('refund_items')->where('refund_id', $refund->id)->get() as $ri) {
                $items[] = ['order_item_id' => $ri->order_item_id, 'quantity' => $ri->quantity];
            }
        }
        // Slices are recomputed from the snapshot (the refund_items rows are the request record).
        $s = $this->slicesForPosting($order, $refund, $scope, $items);
        $amount = $s['amount'];
        if ($amount->isZero()) {
            return null;
        }
        $c = $this->config;
        $recognized = JournalEntry::where('source_key', 'ORDER_RECOGNIZED:' . $order->id)->where('status', JournalEntry::POSTED)->exists();
        $inter = (bool) $order->is_inter_state;
        $lines = [];
        $creditNote = null;

        if ($recognized) {
            $cgT = MoneyBridge::zero(); $sgT = MoneyBridge::zero(); $igT = MoneyBridge::zero(); $taxableT = MoneyBridge::zero();
            foreach ($s['lines'] as $itemId => $l) {
                $dims = ['order_id' => $order->id, 'order_item_id' => $itemId, 'refund_id' => $refund->id];
                if (!$l['taxable']->isZero()) { $lines[] = ['account' => $c->accountCode('product_sales'), 'debit' => $l['taxable'], 'description' => 'Refund: sales reversed'] + $dims; }
                if (!$l['cgst']->isZero()) { $lines[] = ['account' => $c->accountCode('cgst_payable'), 'debit' => $l['cgst'], 'tax_kind' => 'cgst'] + $dims; }
                if (!$l['sgst']->isZero()) { $lines[] = ['account' => $c->accountCode('sgst_payable'), 'debit' => $l['sgst'], 'tax_kind' => 'sgst'] + $dims; }
                if (!$l['igst']->isZero()) { $lines[] = ['account' => $c->accountCode('igst_payable'), 'debit' => $l['igst'], 'tax_kind' => 'igst'] + $dims; }
                if (!$l['vendor_share']->isZero() && $l['shop_id']) {
                    $vd = $dims + ['shop_id' => (int) $l['shop_id']];
                    $lines[] = ['account' => $c->accountCode('vendor_payables'), 'debit' => $l['vendor_share'], 'description' => 'Refund: vendor payable reversed'] + $vd;
                    $lines[] = ['account' => $c->accountCode('vendor_settlement_cost'), 'credit' => $l['vendor_share'], 'description' => 'Refund: settlement cost reversed'] + $vd;
                }
                $cgT = $cgT->add($l['cgst']); $sgT = $sgT->add($l['sgst']); $igT = $igT->add($l['igst']); $taxableT = $taxableT->add($l['taxable']);
            }
            if ($s['delivery']) {
                $d = $s['delivery'];
                if (!$d['taxable']->isZero()) { $lines[] = ['account' => $c->accountCode('delivery_revenue'), 'debit' => $d['taxable'], 'order_id' => $order->id, 'refund_id' => $refund->id, 'description' => 'Refund: delivery reversed']; }
                foreach ([['cgst_payable', $d['cgst'], 'cgst'], ['sgst_payable', $d['sgst'], 'sgst'], ['igst_payable', $d['igst'], 'igst']] as [$role, $amt, $kind]) {
                    if (!$amt->isZero()) { $lines[] = ['account' => $c->accountCode($role), 'debit' => $amt, 'order_id' => $order->id, 'refund_id' => $refund->id, 'tax_kind' => $kind]; }
                }
                $cgT = $cgT->add($d['cgst']); $sgT = $sgT->add($d['sgst']); $igT = $igT->add($d['igst']); $taxableT = $taxableT->add($d['taxable']);
            }
            if (!$s['platform_discount']->isZero()) {
                $lines[] = ['account' => $c->accountCode('discounts_given'), 'credit' => $s['platform_discount'], 'order_id' => $order->id, 'refund_id' => $refund->id, 'description' => 'Refund: discount reversed'];
            }
            $lines[] = ['account' => $c->accountCode('customer_refund_payable'), 'credit' => $amount, 'order_id' => $order->id, 'customer_id' => $order->customer_id, 'refund_id' => $refund->id, 'description' => 'Refund payable ' . $order->tracking_number];
            // paise reconciliation: customer-side credit + discount must equal the reversed debits
            $debits = $taxableT->add($cgT)->add($sgT)->add($igT);
            $credits = $amount->add($s['platform_discount']);
            $diff = $debits->subtract($credits);
            if (!$diff->isZero()) {
                $lines[] = $diff->isNegative()
                    ? ['account' => $c->accountCode('rounding_differences'), 'debit' => Money::fromMinor(-$diff->amountMinor()), 'order_id' => $order->id, 'description' => 'Refund rounding']
                    : ['account' => $c->accountCode('rounding_differences'), 'credit' => $diff, 'order_id' => $order->id, 'description' => 'Refund rounding'];
            }
            $creditNote = ['taxable' => $taxableT, 'cgst' => $cgT, 'sgst' => $sgT, 'igst' => $igT];
        } else {
            $captured = JournalEntry::where('source_key', 'like', 'PAYMENT_CAPTURED:%:order:' . $order->id)->where('status', JournalEntry::POSTED)->exists();
            $walletFull = strtoupper((string) $order->payment_gateway) === 'FULL_WALLET_PAYMENT';
            if (!$captured && !$walletFull) {
                return null; // nothing was collected — nothing to refund on the books
            }
            $lines[] = ['account' => $c->accountCode($walletFull ? 'customer_wallet_liability' : 'customer_advances'), 'debit' => $amount, 'order_id' => $order->id, 'customer_id' => $order->customer_id, 'refund_id' => $refund->id];
            $lines[] = ['account' => $c->accountCode('customer_refund_payable'), 'credit' => $amount, 'order_id' => $order->id, 'customer_id' => $order->customer_id, 'refund_id' => $refund->id];
        }

        return DB::transaction(function () use ($refund, $order, $s, $lines, $key, $actor, $recognized, $creditNote, $amount, $scope) {
            $je = $this->journal->postLines($lines, [
                'source_type' => 'REFUND_POSTED', 'source_id' => $refund->id, 'source_key' => $key,
                'entry_date' => Carbon::today()->toDateString(), 'reference_type' => 'order', 'reference_id' => $order->id,
                'description' => ucfirst($scope) . ' refund #' . $refund->id . ' for ' . $order->tracking_number,
                'metadata' => ['refund_id' => $refund->id, 'scope' => $scope, 'lines' => array_map(fn ($l) => ['qty' => $l['quantity'], 'amount' => $l['amount']->toDecimal(), 'vendor_share' => $l['vendor_share']->toDecimal()], $s['lines'])],
                'actor' => $actor,
            ]);
            if ($recognized) {
                $byItem = [];
                foreach ($s['lines'] as $itemId => $l) {
                    if (!$l['vendor_share']->isZero() && $l['shop_id']) {
                        $byItem[$itemId] = ['shop_id' => (int) $l['shop_id'], 'amount' => $l['vendor_share']->toDecimal()];
                    }
                }
                (new VendorLedgerService())->reverseLinesPartial($order, $byItem, 'refund #' . $refund->id, $je, (int) $refund->id);
                $cn = $this->issueCreditNote($order, $refund, $creditNote, $amount, $s, $je);
                $refund->forceFill(['credit_note_id' => $cn])->saveQuietly();
                if ($scope === 'full') {
                    $order->forceFill(['financial_status' => AccountingPostingService::DERECOGNIZED])->saveQuietly();
                }
            }
            $refund->forceFill(['journal_entry_id' => $je->id])->saveQuietly();
            AccountingAuditLog::record('refund', $refund->id, 'posted', null, ['journal' => $je->entry_number, 'amount' => $amount->toDecimal(), 'scope' => $scope], null, $key, $actor);
            OrderEvent::record($order->id, 'accounting.refund_posted', ['refund_id' => $refund->id, 'journal' => $je->entry_number, 'amount' => $amount->toDecimal()], 'Refund posted');
            return $je;
        });
    }

    /** Pay the refund out and clear the refund payable. Idempotent (REFUND_PAID:{id}). */
    public function payout(Refund $refund, string $method, ?string $actor = null): ?JournalEntry
    {
        if (!$this->config->enabled()) {
            return null;
        }
        $key = 'REFUND_PAID:' . $refund->id;
        if ($existing = JournalEntry::where('source_key', $key)->first()) {
            return $existing;
        }
        if (!$refund->journal_entry_id) {
            return null; // nothing was posted (nothing collected) — no payout on the books
        }
        $order = Order::findOrFail($refund->order_id);
        $amount = MoneyBridge::toMoney($refund->requested_amount ?? $refund->amount);
        if ($amount->isZero()) {
            return null;
        }
        $c = $this->config;
        $gatewayRefundId = null;
        $creditRole = match ($method) {
            'wallet'  => 'customer_wallet_liability',
            'gateway' => 'gateway_receivable',
            default   => 'bank',
        };
        if ($method === 'gateway') {
            $paymentId = (string) ($order->gateway_payment_id ?? '');
            if ($paymentId === '') {
                throw new \RuntimeException('Order ' . $order->tracking_number . ' has no captured gateway payment to refund against.');
            }
            $res = ($this->gatewayRefunder)($paymentId, $amount->amountMinor(), 'refund:' . $refund->id);
            $gatewayRefundId = (string) ($res['id'] ?? '');
        }
        return DB::transaction(function () use ($refund, $order, $amount, $method, $creditRole, $gatewayRefundId, $key, $actor, $c) {
            $je = $this->journal->postLines([
                ['account' => $c->accountCode('customer_refund_payable'), 'debit' => $amount, 'order_id' => $order->id, 'customer_id' => $order->customer_id, 'refund_id' => $refund->id],
                ['account' => $c->accountCode($creditRole), 'credit' => $amount, 'order_id' => $order->id, 'customer_id' => $order->customer_id, 'refund_id' => $refund->id, 'description' => 'Refund paid via ' . $method . ($gatewayRefundId ? ' ' . $gatewayRefundId : '')],
            ], ['source_type' => 'REFUND_PAID', 'source_id' => $refund->id, 'source_key' => $key, 'reference_type' => 'order', 'reference_id' => $order->id, 'description' => 'Refund #' . $refund->id . ' paid (' . $method . ')', 'actor' => $actor]);
            $refund->forceFill(['method' => $method, 'gateway_refund_id' => $gatewayRefundId, 'refunded_at' => Carbon::now(), 'paid_journal_entry_id' => $je->id])->saveQuietly();
            AccountingAuditLog::record('refund', $refund->id, 'paid', null, ['journal' => $je->entry_number, 'method' => $method, 'gateway_refund_id' => $gatewayRefundId], null, $key, $actor);
            return $je;
        });
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** Slices for posting: items from refund_items; partial from requested_amount; full from the snapshot. */
    private function slicesForPosting(Order $order, Refund $refund, string $scope, array $items): array
    {
        // At posting time the refund itself is already 'approved' (the controller flipped it), so
        // it is excluded from the "already refunded" total and quantities.
        return $this->slices($order, $scope, $items, $scope === 'partial' ? (string) ($refund->requested_amount ?? $refund->amount) : null, (int) $refund->id);
    }

    /** Units already refunded per line (approved refunds' refund_items). */
    private function refundedQuantities(Order $order, ?int $excludeRefundId = null): array
    {
        try {
            return DB::table('refund_items')->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')
                ->where('refunds.order_id', $order->id)->where('refunds.status', 'approved')
                ->when($excludeRefundId, fn ($q) => $q->where('refunds.id', '!=', $excludeRefundId))
                ->groupBy('refund_items.order_item_id')->selectRaw('refund_items.order_item_id, SUM(refund_items.quantity) as q')
                ->pluck('q', 'refund_items.order_item_id')->map(fn ($v) => (int) $v)->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function issueCreditNote(Order $order, Refund $refund, array $t, Money $total, array $s, JournalEntry $je): ?int
    {
        try {
            $fy = $this->financialYear(Carbon::today());
            $number = sprintf('CN-%s-%06d', $fy, AccountingSequence::next('credit_note:' . $fy));
            return (int) DB::table('credit_notes')->insertGetId([
                'number' => $number, 'order_id' => $order->id, 'refund_id' => $refund->id, 'customer_id' => $order->customer_id,
                'issue_date' => Carbon::today()->toDateString(), 'taxable_value' => $t['taxable']->toDecimal(),
                'cgst_amount' => $t['cgst']->toDecimal(), 'sgst_amount' => $t['sgst']->toDecimal(), 'igst_amount' => $t['igst']->toDecimal(),
                'total' => $total->toDecimal(), 'reason' => $refund->title ?: 'Refund #' . $refund->id, 'journal_entry_id' => $je->id,
                'lines' => json_encode(array_map(fn ($l, $id) => ['order_item_id' => $id, 'qty' => $l['quantity'], 'taxable' => $l['taxable']->toDecimal(), 'cgst' => $l['cgst']->toDecimal(), 'sgst' => $l['sgst']->toDecimal(), 'igst' => $l['igst']->toDecimal()], $s['lines'], array_keys($s['lines']))),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('credit note issue failed', ['refund_id' => $refund->id, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** Indian financial year label, e.g. 2026-27 for dates from 1 Apr 2026. */
    private function financialYear(Carbon $d): string
    {
        $start = $d->month >= 4 ? $d->year : $d->year - 1;
        return $start . '-' . substr((string) ($start + 1), 2);
    }

    private function floor(Money $m): Money
    {
        return $m->isNegative() ? MoneyBridge::zero() : $m;
    }
}
