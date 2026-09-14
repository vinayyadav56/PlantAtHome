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
     *  ['amount' => Money, 'lines' => [item_id => [quantity, taxable, tax, cgst, sgst, igst, discount, vendor_share, shop_id, amount, hsn, rate]],
     *   'delivery' => ['taxable' => Money, 'cgst','sgst','igst'] | null, 'platform_discount' => Money]
     * Every slice is taken from the line's frozen snapshot, NET of what earlier approved refunds
     * already took from that line (quantity- and amount-aware): the last units of a line and a
     * full refund are exact remainders, so repeated slices can never drift a paisa. Refuses to
     * exceed what the customer paid minus refunds already approved.
     */
    public function slices(Order $order, string $scope, array $items = [], ?string $requestedAmount = null, ?int $excludeRefundId = null): array
    {
        $lines = OrderItem::where('order_id', $order->id)->get()->keyBy('id');
        $paid = MoneyBridge::toMoney($order->paid_total);
        $alreadyRefunded = MoneyBridge::toMoney((string) Refund::where('order_id', $order->id)->whereNull('shop_id')->where('status', 'approved')->when($excludeRefundId, fn ($q) => $q->where('id', '!=', $excludeRefundId))->sum('amount'));
        $refundable = $paid->subtract($alreadyRefunded);
        $prior = $this->priorSlices($order, $excludeRefundId);
        $keys = ['taxable', 'cgst', 'sgst', 'igst', 'discount', 'vendor_share', 'amount'];

        // the whole line as the customer paid it: taxable + tax − discount (any funder), vendor share = frozen payable
        $total = function (OrderItem $it) {
            $taxable = MoneyBridge::toMoney($it->taxable_value ?? $it->subtotal ?? 0);
            $cg = MoneyBridge::toMoney($it->cgst_amount ?? 0); $sg = MoneyBridge::toMoney($it->sgst_amount ?? 0); $ig = MoneyBridge::toMoney($it->igst_amount ?? 0);
            $disc = MoneyBridge::toMoney($it->discount_amount ?? 0);
            return ['taxable' => $taxable, 'cgst' => $cg, 'sgst' => $sg, 'igst' => $ig, 'discount' => $disc,
                'vendor_share' => MoneyBridge::toMoney($it->vendor_payable_snapshot ?? 0), 'amount' => $taxable->add($cg)->add($sg)->add($ig)->subtract($disc)];
        };
        $scale = fn (array $t, float $ratio) => array_map(fn (Money $m) => $m->multiply($ratio), $t);
        $remainder = fn (array $t, ?array $p) => $p ? array_combine($keys, array_map(fn ($k) => $this->floor($t[$k]->subtract($p[$k])), $keys)) : $t;
        $out = ['amount' => MoneyBridge::zero(), 'lines' => [], 'delivery' => null, 'platform_discount' => MoneyBridge::zero()];
        $add = function (OrderItem $it, array $slice, int $qty) use (&$out) {
            $slice['tax'] = $slice['cgst']->add($slice['sgst'])->add($slice['igst']);
            $out['lines'][$it->id] = $slice + ['quantity' => $qty, 'shop_id' => $it->assigned_shop_id, 'hsn' => $it->hsn_code, 'rate' => $it->tax_rate];
            $out['amount'] = $out['amount']->add($slice['amount']);
            $out['platform_discount'] = $out['platform_discount']->add($slice['discount']);
        };

        if ($scope === 'items') {
            $want = [];
            foreach ($items as $req) { // the same line twice in one request is ONE request for the summed units
                $want[(int) ($req['order_item_id'] ?? 0)] = ($want[(int) ($req['order_item_id'] ?? 0)] ?? 0) + max(1, (int) ($req['quantity'] ?? 1));
            }
            if (!$want) {
                throw new \InvalidArgumentException('Item refund needs at least one line.');
            }
            foreach ($want as $itemId => $qty) {
                $it = $lines[$itemId] ?? null;
                if (!$it) {
                    throw new \InvalidArgumentException('Line ' . $itemId . ' does not belong to this order.');
                }
                $t = $total($it); $p = $prior[$it->id] ?? null;
                $remainingUnits = (int) $it->order_quantity - (int) ($p['quantity'] ?? 0);
                $remainingAmt = $t['amount']->subtract($p['amount'] ?? MoneyBridge::zero());
                if ($remainingUnits <= 0 || $remainingAmt->isZero() || $remainingAmt->isNegative()) {
                    throw new \InvalidArgumentException('Line ' . $it->id . ' has already been fully refunded.');
                }
                $qty = min($qty, $remainingUnits);
                $slice = $qty === $remainingUnits ? $remainder($t, $p) : $scale($t, $qty / max(1, (int) $it->order_quantity));
                if ($slice['amount']->amountMinor() > $remainingAmt->amountMinor()) {
                    $slice = $remainder($t, $p);
                }
                $add($it, $slice, $qty);
            }
        } elseif ($scope === 'partial') {
            $want = MoneyBridge::toMoney($requestedAmount ?? '0');
            if ($want->isZero() || $want->isNegative()) {
                throw new \InvalidArgumentException('A partial refund needs a positive amount.');
            }
            // Allocate across lines by what is STILL refundable on each (largest remainder), then split
            // each part into taxable/tax/discount/vendor share by the line's own snapshot proportions.
            $weights = []; $totals = [];
            foreach ($lines as $it) {
                $t = $total($it); $totals[$it->id] = $t;
                $rem = $t['amount']->subtract($prior[$it->id]['amount'] ?? MoneyBridge::zero());
                if (!$rem->isZero() && !$rem->isNegative()) {
                    $weights[$it->id] = $rem->amountMinor();
                }
            }
            if (!$weights) {
                throw new \InvalidArgumentException('Nothing is left to refund on this order.');
            }
            foreach (MoneyBridge::allocate($want, $weights) as $itemId => $part) {
                if ($part->isZero()) {
                    continue;
                }
                $it = $lines[$itemId]; $t = $totals[$itemId]; $p = $prior[$itemId] ?? null;
                if ($part->amountMinor() >= $weights[$itemId]) {
                    $slice = $remainder($t, $p); // closes the line exactly
                } else {
                    $slice = $scale($t, $part->amountMinor() / max(1, $t['amount']->amountMinor()));
                    $slice['amount'] = $part; // the customer gets exactly the allocated part; paise drift in the split reconciles in the journal
                }
                $add($it, $slice, 0);
            }
        } else { // full: everything still outstanding on every line + delivery; the customer gets back exactly what is still refundable
            foreach ($lines as $it) {
                $t = $total($it); $p = $prior[$it->id] ?? null;
                $rem = $t['amount']->subtract($p['amount'] ?? MoneyBridge::zero());
                if ($rem->isZero() || $rem->isNegative()) {
                    continue;
                }
                $add($it, $remainder($t, $p), max(0, (int) $it->order_quantity - (int) ($p['quantity'] ?? 0)));
            }
            // delivery tax = order-level tax − Σ tax of ALL lines (not just the ones being refunded now)
            $allCg = MoneyBridge::sum($lines->map(fn ($it) => MoneyBridge::toMoney($it->cgst_amount ?? 0)));
            $allSg = MoneyBridge::sum($lines->map(fn ($it) => MoneyBridge::toMoney($it->sgst_amount ?? 0)));
            $allIg = MoneyBridge::sum($lines->map(fn ($it) => MoneyBridge::toMoney($it->igst_amount ?? 0)));
            $dTax = MoneyBridge::toMoney($order->delivery_tax_amount ?? 0);
            $dTaxable = $order->delivery_taxable !== null ? MoneyBridge::toMoney($order->delivery_taxable) : MoneyBridge::toMoney($order->delivery_fee ?? 0)->subtract($dTax);
            $out['delivery'] = [
                'taxable' => $this->floor($dTaxable),
                'cgst' => $this->floor(MoneyBridge::toMoney($order->cgst_amount ?? 0)->subtract($allCg)),
                'sgst' => $this->floor(MoneyBridge::toMoney($order->sgst_amount ?? 0)->subtract($allSg)),
                'igst' => $this->floor(MoneyBridge::toMoney($order->igst_amount ?? 0)->subtract($allIg)),
            ];
            $out['amount'] = $refundable; // exactly what is still refundable; the journal reconciles the paise residual
        }

        if ($out['amount']->amountMinor() > $refundable->amountMinor()) {
            throw new \InvalidArgumentException('Refund ' . $out['amount']->toDecimal() . ' exceeds the refundable ' . $refundable->toDecimal() . '.');
        }
        if ($out['amount']->isZero()) {
            throw new \InvalidArgumentException('Nothing is left to refund on this order.');
        }
        return $out;
    }

    /** What earlier APPROVED refunds already took from each line (Money per component), excluding one refund. */
    private function priorSlices(Order $order, ?int $excludeRefundId = null): array
    {
        try {
            $rows = DB::table('refund_items')->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')
                ->where('refunds.order_id', $order->id)->where('refunds.status', 'approved')
                ->when($excludeRefundId, fn ($q) => $q->where('refunds.id', '!=', $excludeRefundId))
                ->groupBy('refund_items.order_item_id')
                ->selectRaw('refund_items.order_item_id, SUM(refund_items.quantity) as quantity, SUM(refund_items.amount) as amount, SUM(refund_items.taxable_value) as taxable, SUM(refund_items.cgst_amount) as cgst, SUM(refund_items.sgst_amount) as sgst, SUM(refund_items.igst_amount) as igst, SUM(refund_items.discount_amount) as discount, SUM(refund_items.vendor_share) as vendor_share')
                ->get();
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->order_item_id] = ['quantity' => (int) $r->quantity, 'amount' => MoneyBridge::toMoney((string) $r->amount), 'taxable' => MoneyBridge::toMoney((string) $r->taxable),
                'cgst' => MoneyBridge::toMoney((string) $r->cgst), 'sgst' => MoneyBridge::toMoney((string) $r->sgst), 'igst' => MoneyBridge::toMoney((string) $r->igst),
                'discount' => MoneyBridge::toMoney((string) $r->discount), 'vendor_share' => MoneyBridge::toMoney((string) $r->vendor_share)];
        }
        return $out;
    }

    /** The customer-side amount a refund actually POSTED (the 2050 credit), or zero when nothing was posted. */
    public function postedAmount(Refund $refund): Money
    {
        if (!$refund->journal_entry_id) {
            return MoneyBridge::zero();
        }
        $je = JournalEntry::find($refund->journal_entry_id);
        if (!$je) {
            return MoneyBridge::zero();
        }
        $code = $this->config->accountCode('customer_refund_payable');
        $q = DB::table('acc_journal_lines as l')->join('acc_accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_entry_id', $je->id)->where('a.code', $code);
        if ($je->source_type === 'REFUND_POSTED') {
            $q->where('l.refund_id', $refund->id);
        }
        $credit = MoneyBridge::toMoney((string) $q->sum('l.credit'));
        if ($je->source_type !== 'REFUND_POSTED') { // linked to a CANCEL_REFUND_PAYABLE entry: pay what this refund asked, never more than the payable
            $asked = MoneyBridge::toMoney($refund->requested_amount ?? $refund->amount);
            return $asked->amountMinor() < $credit->amountMinor() ? $asked : $credit;
        }
        return $credit;
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
            foreach (DB::table('refund_items')->where('refund_id', $refund->id)->where('quantity', '>', 0)->get() as $ri) {
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
                $dims = ['order_id' => $order->id, 'order_item_id' => $itemId, 'refund_id' => $refund->id, 'hsn_code' => $l['hsn'] ?? null, 'tax_rate' => $l['rate'] ?? null];
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
                $lines[] = ['account' => $c->accountCode('discounts_given'), 'credit' => $s['platform_discount'], 'order_id' => $order->id, 'refund_id' => $refund->id, 'description' => 'Refund: discounts given reversed'];
            }
            $lines[] = ['account' => $c->accountCode('customer_refund_payable'), 'credit' => $amount, 'order_id' => $order->id, 'customer_id' => $order->customer_id, 'refund_id' => $refund->id, 'description' => 'Refund payable ' . $order->tracking_number];
            // paise reconciliation: customer-side credit + discount must equal the reversed debits
            $debits = $taxableT->add($cgT)->add($sgT)->add($igT);
            $credits = $amount->add($s['platform_discount']);
            $diff = $debits->subtract($credits);
            // a FULL refund gives back the legacy flat tax-class add-on too (recognition booked it as output tax)
            $salesTax = MoneyBridge::toMoney($order->sales_tax ?? 0);
            if ($scope === 'full' && $diff->isNegative() && !$salesTax->isZero() && !$salesTax->isNegative()) {
                $short = Money::fromMinor(-$diff->amountMinor());
                $legacy = $short->amountMinor() < $salesTax->amountMinor() ? $short : $salesTax;
                $desc = 'Refund: legacy tax-class add-on reversed';
                if ($inter) {
                    $lines[] = ['account' => $c->accountCode('igst_payable'), 'debit' => $legacy, 'order_id' => $order->id, 'refund_id' => $refund->id, 'tax_kind' => 'igst', 'description' => $desc];
                    $igT = $igT->add($legacy);
                } else {
                    [$lCg, $lSg] = array_values(MoneyBridge::allocate($legacy, ['cgst' => 1, 'sgst' => 1]));
                    $lines[] = ['account' => $c->accountCode('cgst_payable'), 'debit' => $lCg, 'order_id' => $order->id, 'refund_id' => $refund->id, 'tax_kind' => 'cgst', 'description' => $desc];
                    $lines[] = ['account' => $c->accountCode('sgst_payable'), 'debit' => $lSg, 'order_id' => $order->id, 'refund_id' => $refund->id, 'tax_kind' => 'sgst', 'description' => $desc];
                    $cgT = $cgT->add($lCg); $sgT = $sgT->add($lSg);
                }
                $debits = $debits->add($legacy);
                $diff = $debits->subtract($credits);
            }
            if (!$diff->isZero()) {
                $lines[] = $diff->isNegative()
                    ? ['account' => $c->accountCode('rounding_differences'), 'debit' => Money::fromMinor(-$diff->amountMinor()), 'order_id' => $order->id, 'description' => 'Refund rounding']
                    : ['account' => $c->accountCode('rounding_differences'), 'credit' => $diff, 'order_id' => $order->id, 'description' => 'Refund rounding'];
            }
            $creditNote = ['taxable' => $taxableT, 'cgst' => $cgT, 'sgst' => $sgT, 'igst' => $igT];
        } else {
            // Cancelled before delivery: the advance already moved to Refund Payable (CANCEL_REFUND_PAYABLE) —
            // link this refund to that entry and pay out from it instead of booking the payable twice.
            if ($cancel = JournalEntry::where('source_key', 'CANCEL_REFUND_PAYABLE:' . $order->id)->where('status', JournalEntry::POSTED)->first()) {
                $refund->forceFill(['journal_entry_id' => $cancel->id])->saveQuietly();
                AccountingAuditLog::record('refund', $refund->id, 'linked', null, ['journal' => $cancel->entry_number], 'refund payable already booked at cancellation', $key, $actor);
                return $cancel;
            }
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
        $amount = $this->postedAmount($refund); // pay what was POSTED, never the request-time figure
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
                'lines' => json_encode(array_merge(
                    array_map(fn ($l, $id) => ['order_item_id' => $id, 'qty' => $l['quantity'], 'hsn' => $l['hsn'] ?? null, 'rate' => $l['rate'] ?? null, 'taxable' => $l['taxable']->toDecimal(), 'cgst' => $l['cgst']->toDecimal(), 'sgst' => $l['sgst']->toDecimal(), 'igst' => $l['igst']->toDecimal()], $s['lines'], array_keys($s['lines'])),
                    $s['delivery'] ? [['order_item_id' => null, 'label' => 'Delivery charge', 'qty' => 1, 'hsn' => null, 'rate' => null, 'taxable' => $s['delivery']['taxable']->toDecimal(), 'cgst' => $s['delivery']['cgst']->toDecimal(), 'sgst' => $s['delivery']['sgst']->toDecimal(), 'igst' => $s['delivery']['igst']->toDecimal()]] : []
                )),
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
