<?php

namespace Marvel\Services\Accounting;

use App\Shared\Domain\ValueObject\Money;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Accounting\AccountingAuditLog;
use Marvel\Database\Models\Accounting\JournalEntry;
use Marvel\Database\Models\Accounting\PaymentEvent;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderEvent;
use Marvel\Database\Models\OrderItem;
use Marvel\Enums\OrderStatus;
use Marvel\Enums\PaymentGatewayType;
use Marvel\Services\Accounting\Exceptions\ImmutableJournalException;

/**
 * The central posting service (spec §29): business events call ONE method here; no
 * accounting logic lives in controllers or traits. Every method is idempotent by journal
 * source_key, runs inside a DB transaction with the caller's state change, and in strict
 * mode throws so the business change rolls back (spec §45). When accounting is disabled
 * every method is a no-op.
 *
 * Principal recognition (D2) at parent order COMPLETED, per order line, from the immutable
 * snapshot only (spec §13, §16-18, §58):
 *   DR customer money (advances | wallet | COD receivable)   paid_total
 *   DR Discounts Given (platform-funded)                      Σ line discount
 *   CR Product Sales                                          Σ line taxable_value
 *   CR CGST/SGST | IGST                                       Σ line tax (+ delivery share)
 *   CR Delivery Revenue                                       delivery_taxable
 *   DR Vendor Settlement Cost / CR Vendor Payables[shop]      Σ vendor payable (assigned VENDOR_SUPPLIED lines)
 */
class AccountingPostingService
{
    public const UNRECOGNIZED = 'unrecognized';
    public const RECOGNIZED = 'recognized';
    public const DERECOGNIZED = 'derecognized';
    public const REQUIRES_RECONCILIATION = 'requires_reconciliation';

    private static ?self $instance = null;

    public function __construct(
        private readonly AccountingConfig $config,
        private readonly JournalService $journal,
        private readonly VendorPayableCalculator $calculator,
    ) {
    }

    public static function make(): self
    {
        return new self(new AccountingConfig(), new JournalService(), new VendorPayableCalculator());
    }

    public static function enabled(): bool
    {
        try {
            return (new AccountingConfig())->enabled();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function config(): AccountingConfig
    {
        return $this->config;
    }

    /**
     * The single hook every order-status seam calls (changeOrderStatus + the saveQuietly
     * rollup). Parent orders only; child orders carry no financial snapshot.
     */
    public static function onOrderStatusChanged(Order $order, ?string $prev, ?string $new, ?string $actor = null): void
    {
        if (!self::enabled() || $order->parent_id || $prev === $new) {
            return;
        }
        $svc = self::make();
        if ($new === OrderStatus::COMPLETED) {
            $svc->recognizeOrder($order, $actor);
        } elseif ($prev === OrderStatus::COMPLETED && in_array($new, [OrderStatus::CANCELLED, OrderStatus::REFUNDED, OrderStatus::FAILED], true)) {
            $svc->derecognizeOrder($order, 'order ' . $new, $actor);
        } elseif ($new === OrderStatus::CANCELLED) {
            $svc->cancelBeforeRecognition($order, $actor);
        }
    }

    // ── recognition ─────────────────────────────────────────────────────────

    /** Recognise revenue, tax, delivery and vendor payables for a completed parent order. */
    public function recognizeOrder(Order $order, ?string $actor = null): ?JournalEntry
    {
        return $this->guarded('recognizeOrder', $order, function () use ($order, $actor) {
            $key = 'ORDER_RECOGNIZED:' . $order->id;
            if ($existing = JournalEntry::where('source_key', $key)->first()) {
                if ($existing->status === JournalEntry::POSTED && $order->financial_status !== self::RECOGNIZED) {
                    $this->markOrder($order, self::RECOGNIZED, $existing->id);
                }
                return $existing;
            }
            $items = OrderItem::where('order_id', $order->id)->get();
            if ($items->isEmpty()) {
                $this->flag($order, 'no order_items to recognise (pre-P4 order?)');
                return null;
            }

            $c = $this->config;
            $inter = (bool) $order->is_inter_state;
            $notes = [];
            $lines = [];

            // customer side
            $paid = MoneyBridge::toMoney($order->paid_total);
            $customerRole = $this->customerRole($order);
            $lines[] = ['account' => $c->accountCode($customerRole), 'debit' => $paid, 'order_id' => $order->id, 'customer_id' => $order->customer_id, 'description' => 'Order ' . $order->tracking_number];

            // revenue + tax per line (from the immutable snapshot)
            $sumTaxable = MoneyBridge::zero();
            $lineCgst = MoneyBridge::zero(); $lineSgst = MoneyBridge::zero(); $lineIgst = MoneyBridge::zero();
            $platformDiscount = MoneyBridge::zero();
            $hasLineDiscount = false;
            foreach ($items as $it) {
                $taxable = MoneyBridge::toMoney($it->taxable_value ?? $it->subtotal ?? 0);
                $cg = MoneyBridge::toMoney($it->cgst_amount ?? 0); $sg = MoneyBridge::toMoney($it->sgst_amount ?? 0); $ig = MoneyBridge::toMoney($it->igst_amount ?? 0);
                $dims = ['order_id' => $order->id, 'order_item_id' => $it->id, 'hsn_code' => $it->hsn_code, 'tax_rate' => $it->tax_rate];
                if (!$taxable->isZero()) {
                    $lines[] = ['account' => $c->accountCode('product_sales'), 'credit' => $taxable] + $dims;
                }
                if (!$cg->isZero()) { $lines[] = ['account' => $c->accountCode('cgst_payable'), 'credit' => $cg, 'tax_kind' => 'cgst'] + $dims; }
                if (!$sg->isZero()) { $lines[] = ['account' => $c->accountCode('sgst_payable'), 'credit' => $sg, 'tax_kind' => 'sgst'] + $dims; }
                if (!$ig->isZero()) { $lines[] = ['account' => $c->accountCode('igst_payable'), 'credit' => $ig, 'tax_kind' => 'igst'] + $dims; }
                $sumTaxable = $sumTaxable->add($taxable); $lineCgst = $lineCgst->add($cg); $lineSgst = $lineSgst->add($sg); $lineIgst = $lineIgst->add($ig);
                if ($it->discount_amount !== null) {
                    $hasLineDiscount = true;
                    if (($it->discount_funded_by ?? 'platform') !== 'vendor') {
                        $platformDiscount = $platformDiscount->add(MoneyBridge::toMoney($it->discount_amount));
                    }
                }
            }
            if (!$hasLineDiscount) { // legacy line set: the whole order discount is platform-funded
                $platformDiscount = MoneyBridge::toMoney($order->discount ?? 0);
            }
            if (!$platformDiscount->isZero()) {
                $lines[] = ['account' => $c->accountCode('discounts_given'), 'debit' => $platformDiscount, 'order_id' => $order->id, 'description' => 'Platform-funded discount'];
            }

            // delivery: revenue + its tax share (order-level tax − Σ line tax, from the same snapshot)
            $deliveryFee = MoneyBridge::toMoney($order->delivery_fee ?? 0);
            $deliveryTax = MoneyBridge::toMoney($order->delivery_tax_amount ?? 0);
            $deliveryTaxable = $order->delivery_taxable !== null ? MoneyBridge::toMoney($order->delivery_taxable) : $deliveryFee->subtract($deliveryTax);
            if (!$deliveryTaxable->isZero()) {
                $lines[] = ['account' => $c->accountCode('delivery_revenue'), 'credit' => $deliveryTaxable, 'order_id' => $order->id, 'description' => 'Delivery charge'];
            }
            $dCg = $this->floor(MoneyBridge::toMoney($order->cgst_amount ?? 0)->subtract($lineCgst));
            $dSg = $this->floor(MoneyBridge::toMoney($order->sgst_amount ?? 0)->subtract($lineSgst));
            $dIg = $this->floor(MoneyBridge::toMoney($order->igst_amount ?? 0)->subtract($lineIgst));
            foreach ([['cgst_payable', $dCg, 'cgst'], ['sgst_payable', $dSg, 'sgst'], ['igst_payable', $dIg, 'igst']] as [$role, $amt, $kind]) {
                if (!$amt->isZero()) {
                    $lines[] = ['account' => $c->accountCode($role), 'credit' => $amt, 'order_id' => $order->id, 'tax_kind' => $kind, 'description' => 'GST on delivery'];
                }
            }

            // rounding reconciliation (spec §54): customer side + discount must equal the credits
            $credits = $sumTaxable->add($lineCgst)->add($lineSgst)->add($lineIgst)->add($deliveryTaxable)->add($dCg)->add($dSg)->add($dIg);
            $debits = $paid->add($platformDiscount);
            $diff = $debits->subtract($credits); // >0: customer paid more than the snapshot explains
            $beyondTolerance = abs($diff->amountMinor()) > $c->roundingToleranceMinor();
            if (!$diff->isZero()) {
                $lines[] = $diff->isNegative()
                    ? ['account' => $c->accountCode('rounding_differences'), 'debit' => Money::fromMinor(-$diff->amountMinor()), 'order_id' => $order->id, 'description' => 'Rounding residual']
                    : ['account' => $c->accountCode('rounding_differences'), 'credit' => $diff, 'order_id' => $order->id, 'description' => 'Rounding residual'];
                $notes[] = 'rounding residual ' . $diff->toDecimal();
            }

            // vendor payables per assigned VENDOR_SUPPLIED line (cost-sheet or commission, snapshotted)
            $shopModes = $this->shopModes($items->pluck('assigned_shop_id')->filter()->unique()->values()->all());
            $itemSnapshots = [];
            $ledgerLines = [];
            $calcs = $this->calculator->forLines($items->filter(fn ($i) => ($i->ownership_model ?: 'VENDOR_SUPPLIED') !== 'PLATFORM_OWNED'), $shopModes);
            foreach ($items as $it) {
                $owner = $it->ownership_model ?: 'VENDOR_SUPPLIED';
                if ($owner === 'PLATFORM_OWNED') {
                    $notes[] = 'line ' . $it->id . ' PLATFORM_OWNED (COGS posts via inventory ledger)';
                    continue;
                }
                if (!$it->assigned_shop_id) {
                    $notes[] = 'line ' . $it->id . ' has no vendor assignment';
                    continue;
                }
                $mode = $shopModes[$it->assigned_shop_id]['commission_mode'] ?? VendorPayableCalculator::COST_SHEET;
                if (($shopModes[$it->assigned_shop_id]['recognition_mode'] ?? 'principal') === 'agent') {
                    $notes[] = 'shop ' . $it->assigned_shop_id . ' is AGENT mode (agent variant pending) — posted as principal';
                }
                $calc = $calcs[$it->id] ?? $this->calculator->forItem($it, $mode);
                $itemSnapshots[$it->id] = VendorPayableCalculator::snapshot($calc);
                $ledgerLines[$it->id] = [
                    'payable' => $calc['payable']->toDecimal(), 'mode' => $calc['mode'], 'rate' => $calc['commission_rate'], 'rule_id' => $calc['rule_id'] ?? null, 'scope' => $calc['scope'] ?? null,
                    'commission' => $calc['commission']->toDecimal(), 'gross' => $calc['gross']->toDecimal(),
                    'unit_rate' => MoneyBridge::toMoney($it->vendor_price_snapshot ?? 0)->toDecimal(), 'vendor_discount' => $calc['vendor_discount']->toDecimal(),
                ];
                if ($calc['payable']->isZero()) {
                    continue;
                }
                $vdims = ['order_id' => $order->id, 'order_item_id' => $it->id, 'shop_id' => (int) $it->assigned_shop_id];
                $lines[] = ['account' => $c->accountCode('vendor_settlement_cost'), 'debit' => $calc['payable'], 'description' => 'Vendor share (' . $calc['mode'] . ')'] + $vdims;
                $lines[] = ['account' => $c->accountCode('vendor_payables'), 'credit' => $calc['payable'], 'description' => 'Payable to vendor'] + $vdims;
            }
            $unassigned = $items->filter(fn ($i) => ($i->ownership_model ?: 'VENDOR_SUPPLIED') !== 'PLATFORM_OWNED' && !$i->assigned_shop_id)->count();

            $meta = [
                'source_type'    => 'ORDER_RECOGNIZED',
                'source_id'      => $order->id,
                'source_key'     => $key,
                'entry_date'     => Carbon::today()->toDateString(),
                'reference_type' => 'order',
                'reference_id'   => $order->id,
                'description'    => 'Revenue recognition for order ' . $order->tracking_number,
                'metadata'       => ['tracking_number' => $order->tracking_number, 'inter_state' => $inter, 'notes' => $notes, 'residual' => $diff->toDecimal()],
                'actor'          => $actor,
                'requires_reconciliation' => $beyondTolerance || $unassigned > 0,
            ];

            return DB::transaction(function () use ($order, $items, $itemSnapshots, $ledgerLines, $lines, $meta, $beyondTolerance, $unassigned, $notes) {
                if ($beyondTolerance) {
                    // Never post a figure the snapshot cannot explain: keep it as a balanced DRAFT
                    // for an accountant to review, and flag the order.
                    $entry = $this->journal->draft($lines, $meta);
                    $this->markOrder($order, self::REQUIRES_RECONCILIATION, $entry->id, $notes);
                    Log::error('accounting: recognition residual beyond tolerance — drafted, not posted', ['order_id' => $order->id, 'residual' => $meta['metadata']['residual']]);
                } else {
                    $entry = $this->journal->postLines($lines, $meta);
                    $status = $unassigned > 0 ? self::REQUIRES_RECONCILIATION : self::RECOGNIZED;
                    $this->markOrder($order, $status, $entry->id, $notes);
                    // Vendor sub-ledger (settlement projection) — same transaction, linked to the journal.
                    (new \Marvel\Services\VendorLedgerService())->recordRecognition($order, $items, $ledgerLines, $entry);
                }
                $now = Carbon::now();
                foreach ($items as $it) {
                    $upd = ['recognized_at' => $now, 'journal_entry_id' => $entry->id] + ($itemSnapshots[$it->id] ?? []);
                    $it->update($this->onlyExistingColumns('order_items', $upd));
                }
                AccountingAuditLog::record('order', $order->id, 'recognized', null, ['journal' => $entry->entry_number ?? 'draft', 'status' => $order->financial_status], null, $meta['source_key'], $meta['actor']);
                OrderEvent::record($order->id, 'accounting.recognized', ['journal' => $entry->entry_number ?? 'draft', 'status' => $order->financial_status], 'Revenue recognised');
                return $entry;
            });
        });
    }

    /** Reverse a recognised order (post-delivery cancel/refund/failure). Idempotent. */
    public function derecognizeOrder(Order $order, string $reason, ?string $actor = null): ?JournalEntry
    {
        return $this->guarded('derecognizeOrder', $order, function () use ($order, $reason, $actor) {
            $orig = JournalEntry::where('source_key', 'ORDER_RECOGNIZED:' . $order->id)->first();
            if (!$orig) {
                return null; // never recognised — nothing to reverse
            }
            // A FULL refund already reversed revenue/tax/payables through RefundService (and leaves
            // the refund payable, not the customer advance, on the books) — never reverse twice.
            if ($order->financial_status === self::DERECOGNIZED && JournalEntry::where('source_type', 'REFUND_POSTED')->where('reference_type', 'order')->where('reference_id', $order->id)->exists()) {
                return null;
            }
            if ($orig->status !== JournalEntry::POSTED) {
                return $orig->reversedBy; // already reversed (or still a draft)
            }
            return DB::transaction(function () use ($order, $orig, $reason, $actor) {
                $rev = $this->journal->reverse($orig, $reason, $actor);
                $this->markOrder($order, self::DERECOGNIZED, $orig->id);
                (new \Marvel\Services\VendorLedgerService())->reverseRecognition($order, $reason, $rev);
                OrderEvent::record($order->id, 'accounting.derecognized', ['journal' => $rev->entry_number, 'reason' => $reason], 'Revenue reversed');
                return $rev;
            });
        });
    }

    /** Cancelled after a capture but before delivery: the advance becomes a refund payable. */
    public function cancelBeforeRecognition(Order $order, ?string $actor = null): ?JournalEntry
    {
        return $this->guarded('cancelBeforeRecognition', $order, function () use ($order, $actor) {
            if (JournalEntry::where('source_key', 'ORDER_RECOGNIZED:' . $order->id)->exists()) {
                return null; // recognised orders go through derecognizeOrder
            }
            $captured = JournalEntry::where('source_key', 'like', 'PAYMENT_CAPTURED:%:order:' . $order->id)->where('status', JournalEntry::POSTED)->exists();
            if (!$captured) {
                return null; // nothing was collected
            }
            $paid = MoneyBridge::toMoney($order->paid_total);
            if ($paid->isZero()) {
                return null;
            }
            $c = $this->config;
            return $this->journal->postLines([
                ['account' => $c->accountCode('customer_advances'), 'debit' => $paid, 'order_id' => $order->id, 'customer_id' => $order->customer_id],
                ['account' => $c->accountCode('customer_refund_payable'), 'credit' => $paid, 'order_id' => $order->id, 'customer_id' => $order->customer_id],
            ], [
                'source_type' => 'CANCEL_REFUND_PAYABLE', 'source_id' => $order->id, 'source_key' => 'CANCEL_REFUND_PAYABLE:' . $order->id,
                'reference_type' => 'order', 'reference_id' => $order->id, 'description' => 'Cancelled before delivery — refund payable for ' . $order->tracking_number, 'actor' => $actor,
            ]);
        });
    }

    // ── payments ────────────────────────────────────────────────────────────

    /**
     * Record a gateway capture ONCE (payment_events UNIQUE) and post
     *   DR Payment Gateway Receivable (captured) · DR Wallet Liability (wallet part) / CR Customer Advances (paid_total).
     * Fee (when the gateway reports it) posts separately: DR Gateway Charges / CR Gateway Receivable.
     */
    public function recordPaymentCaptured(Order $order, string $gateway, string $gatewayPaymentId, int $amountPaise, int $feePaise = 0, int $taxOnFeePaise = 0, array $payload = [], ?string $actor = null): ?JournalEntry
    {
        return $this->guarded('recordPaymentCaptured', $order, function () use ($order, $gateway, $gatewayPaymentId, $amountPaise, $feePaise, $taxOnFeePaise, $payload, $actor) {
            if ($gatewayPaymentId === '') {
                $this->flag($order, 'capture without a gateway payment id');
                return null;
            }
            $key = 'PAYMENT_CAPTURED:' . $gateway . ':' . $gatewayPaymentId . ':order:' . $order->id;
            return DB::transaction(function () use ($order, $gateway, $gatewayPaymentId, $amountPaise, $feePaise, $taxOnFeePaise, $payload, $actor, $key) {
                $event = $this->recordEvent($gateway, $gatewayPaymentId . ':captured', 'captured', $gatewayPaymentId, $order, $amountPaise, $feePaise, $taxOnFeePaise, $payload);
                if ($event->journal_entry_id) {
                    return JournalEntry::find($event->journal_entry_id); // replayed webhook / reconcile — no-op
                }
                $captured = Money::fromMinor($amountPaise, 'INR');
                $wallet = $this->walletCovered($order);
                $advance = $captured->add($wallet);
                $paid = MoneyBridge::toMoney($order->paid_total);
                $notes = [];
                if (!$advance->equals($paid)) {
                    $notes[] = 'captured ' . $captured->toDecimal() . ' + wallet ' . $wallet->toDecimal() . ' != paid_total ' . $paid->toDecimal();
                }
                $c = $this->config;
                $lines = [['account' => $c->accountCode('gateway_receivable'), 'debit' => $captured, 'order_id' => $order->id, 'customer_id' => $order->customer_id, 'description' => $gateway . ' ' . $gatewayPaymentId]];
                if (!$wallet->isZero()) {
                    $lines[] = ['account' => $c->accountCode('customer_wallet_liability'), 'debit' => $wallet, 'order_id' => $order->id, 'customer_id' => $order->customer_id, 'description' => 'Wallet applied'];
                }
                $lines[] = ['account' => $c->accountCode('customer_advances'), 'credit' => $advance, 'order_id' => $order->id, 'customer_id' => $order->customer_id, 'description' => 'Customer advance for ' . $order->tracking_number];
                $entry = $this->journal->postLines($lines, [
                    'source_type' => 'PAYMENT_CAPTURED', 'source_id' => $gatewayPaymentId, 'source_key' => $key,
                    'reference_type' => 'order', 'reference_id' => $order->id, 'description' => 'Payment captured (' . $gateway . ')',
                    'metadata' => ['notes' => $notes, 'payment_event_id' => $event->id], 'actor' => $actor,
                    'requires_reconciliation' => (bool) $notes,
                ]);
                $event->update(['journal_entry_id' => $entry->id, 'processed_at' => Carbon::now()]);
                $order->forceFill($this->onlyExistingColumns('orders', [
                    'gateway_payment_id' => $gatewayPaymentId, 'captured_amount' => $captured->toDecimal(), 'captured_at' => Carbon::now(),
                ]))->saveQuietly();
                if ($notes) {
                    $this->flag($order, implode('; ', $notes));
                }
                if ($feePaise > 0) {
                    $fee = Money::fromMinor($feePaise, 'INR');
                    $this->journal->postLines([
                        ['account' => $c->accountCode('gateway_charges'), 'debit' => $fee, 'order_id' => $order->id, 'description' => 'Gateway fee ' . $gatewayPaymentId, 'metadata' => ['tax_on_fee' => Money::fromMinor($taxOnFeePaise, 'INR')->toDecimal()]],
                        ['account' => $c->accountCode('gateway_receivable'), 'credit' => $fee, 'order_id' => $order->id],
                    ], ['source_type' => 'PAYMENT_FEE', 'source_id' => $gatewayPaymentId, 'source_key' => 'PAYMENT_FEE:' . $gateway . ':' . $gatewayPaymentId, 'reference_type' => 'order', 'reference_id' => $order->id, 'description' => 'Gateway charges', 'actor' => $actor]);
                }
                OrderEvent::record($order->id, 'accounting.payment_captured', ['journal' => $entry->entry_number, 'amount' => $captured->toDecimal()], 'Payment captured');
                return $entry;
            });
        });
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** No-op when disabled; strict → rethrow (caller's transaction rolls back); else log + flag. */
    private function guarded(string $op, Order $order, \Closure $fn): mixed
    {
        if (!$this->config->enabled()) {
            return null;
        }
        try {
            return $fn();
        } catch (\Throwable $e) {
            Log::error('accounting posting failed', ['op' => $op, 'order_id' => $order->id, 'error' => $e->getMessage()]);
            if ($this->config->strict()) {
                throw $e;
            }
            $this->flag($order, $op . ' failed: ' . $e->getMessage());
            return null;
        }
    }

    private function recordEvent(string $gateway, string $eventId, string $type, string $paymentId, Order $order, int $amountPaise, int $feePaise, int $taxPaise, array $payload): PaymentEvent
    {
        $attrs = [
            'event_type' => $type, 'gateway_payment_id' => $paymentId, 'gateway_order_id' => $payload['order_id'] ?? null,
            'order_id' => $order->id, 'amount' => Money::fromMinor($amountPaise, 'INR')->toDecimal(),
            'fee' => $feePaise > 0 ? Money::fromMinor($feePaise, 'INR')->toDecimal() : null,
            'tax_on_fee' => $taxPaise > 0 ? Money::fromMinor($taxPaise, 'INR')->toDecimal() : null,
            'status' => $payload['status'] ?? $type, 'payload_hash' => $payload ? hash('sha256', json_encode($payload)) : null,
            'payload' => $payload ?: null, 'received_at' => Carbon::now(),
        ];
        try {
            return PaymentEvent::create(['gateway' => $gateway, 'event_id' => $eventId] + $attrs);
        } catch (QueryException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique') || $e->getCode() === '23000') {
                Log::info('accounting: duplicate payment event ignored', ['gateway' => $gateway, 'event_id' => $eventId]);
                return PaymentEvent::where('gateway', $gateway)->where('event_id', $eventId)->firstOrFail();
            }
            throw $e;
        }
    }

    private function customerRole(Order $order): string
    {
        $gw = strtoupper((string) ($order->payment_gateway ?? PaymentGatewayType::CASH_ON_DELIVERY));
        if (in_array($gw, [PaymentGatewayType::CASH_ON_DELIVERY, PaymentGatewayType::CASH], true)) {
            return 'customer_receivable';
        }
        if ($gw === PaymentGatewayType::FULL_WALLET_PAYMENT) {
            return 'customer_wallet_liability';
        }
        return 'customer_advances';
    }

    private function walletCovered(Order $order): Money
    {
        try {
            $amt = DB::table('order_wallet_points')->where('order_id', $order->id)->sum('amount');
            return MoneyBridge::toMoney($amt ?: 0);
        } catch (\Throwable $e) {
            return MoneyBridge::zero();
        }
    }

    /** @return array<int, array{recognition_mode:string, commission_mode:string}> */
    private function shopModes(array $shopIds): array
    {
        if (!$shopIds) {
            return [];
        }
        try {
            $cols = ['id'];
            foreach (['recognition_mode', 'commission_mode'] as $c) {
                if (Schema::hasColumn('shops', $c)) {
                    $cols[] = $c;
                }
            }
            $out = [];
            foreach (DB::table('shops')->whereIn('id', $shopIds)->get($cols) as $s) {
                $out[(int) $s->id] = ['recognition_mode' => $s->recognition_mode ?? 'principal', 'commission_mode' => $s->commission_mode ?? VendorPayableCalculator::COST_SHEET];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function markOrder(Order $order, string $status, ?int $journalId, array $notes = []): void
    {
        $upd = ['financial_status' => $status];
        if (in_array($status, [self::RECOGNIZED, self::REQUIRES_RECONCILIATION], true) && $journalId) {
            $upd['recognized_at'] = Carbon::now();
            $upd['recognition_journal_id'] = $journalId;
        }
        $order->forceFill($this->onlyExistingColumns('orders', $upd))->saveQuietly();
    }

    private function flag(Order $order, string $note): void
    {
        Log::warning('accounting: order flagged for reconciliation', ['order_id' => $order->id, 'note' => $note]);
        $order->forceFill($this->onlyExistingColumns('orders', ['financial_status' => self::REQUIRES_RECONCILIATION]))->saveQuietly();
        OrderEvent::record($order->id, 'accounting.flagged', ['note' => $note], 'Accounting needs reconciliation');
    }

    private function floor(Money $m): Money
    {
        return $m->isNegative() ? MoneyBridge::zero() : $m;
    }

    /** Deploys migrate in the background — only write columns that exist. */
    private function onlyExistingColumns(string $table, array $attrs): array
    {
        static $cache = [];
        foreach ($attrs as $col => $v) {
            $cache[$table][$col] ??= Schema::hasColumn($table, $col);
            if (!$cache[$table][$col]) {
                unset($attrs[$col]);
            }
        }
        return $attrs;
    }
}
