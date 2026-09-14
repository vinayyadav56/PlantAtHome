<?php

namespace Marvel\Services\Accounting;

use App\Shared\Domain\ValueObject\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Accounting\AccountingAuditLog;
use Marvel\Database\Models\Accounting\JournalEntry;
use Marvel\Database\Models\OrderItem;

/**
 * Inventory ledger for PLATFORM_OWNED stock (spec §20): every movement is an
 * `inventory_transactions` row + a journal, and `inventory_valuations` (weighted average) is
 * updated in the SAME transaction so Σ total_value == GL 1040 at all times.
 *
 *   receipt     DR Inventory / CR Courier-or-Supplier payable (2060, or 2010[supplier])   RECEIPT
 *   sale        DR COGS / CR Inventory at average cost — lines added to the ORDER's own recognition journal
 *   return      DR Inventory / CR COGS at the sale's unit cost                              RETURN_RECEIVED
 *   adjustment  ± DR/CR Inventory vs Other Expenses at average cost                        INVENTORY_ADJUSTMENT
 *   damage      DR Other Expenses / CR Inventory                                            INVENTORY_ADJUSTMENT
 * Vendor-supplied stock (the default) never comes here.
 */
class InventoryLedgerService
{
    public function __construct(private readonly AccountingConfig $config = new AccountingConfig(), private readonly JournalService $journal = new JournalService())
    {
    }

    public static function available(): bool
    {
        static $has = null;
        return $has ??= Schema::hasTable('inventory_valuations');
    }

    /** Stock IN at a cost (goods receipt / manual). Idempotent on $key. */
    public function receipt(int $productId, int $variationId, int $warehouseId, int $qty, string $unitCost, string $key, ?int $supplierShopId = null, ?string $note = null, ?string $actor = null, ?string $referenceType = 'manual', ?int $referenceId = null): ?JournalEntry
    {
        if ($qty <= 0) {
            throw new \InvalidArgumentException('Receipt quantity must be positive.');
        }
        $cost = MoneyBridge::toMoney($unitCost)->multiply($qty);
        if ($cost->isZero() || $cost->isNegative()) {
            throw new \InvalidArgumentException('Receipt cost must be positive.');
        }
        return $this->move('receipt', $productId, $variationId, $warehouseId, $qty, $unitCost, $cost, $key, function ($c, $dims) use ($cost, $supplierShopId) {
            $payable = $supplierShopId ? ['account' => $c->accountCode('vendor_payables'), 'credit' => $cost, 'shop_id' => $supplierShopId] : ['account' => $c->accountCode('other_current_liabilities'), 'credit' => $cost];
            return [['account' => $c->accountCode('inventory'), 'debit' => $cost, 'description' => 'Stock received'] + $dims, $payable + $dims + ['description' => 'Supplier payable']];
        }, 'INVENTORY_RECEIPT', $note, $actor, $referenceType, $referenceId);
    }

    /** ± quantity at average cost (count corrections); negative = write-down. */
    public function adjust(int $productId, int $variationId, int $warehouseId, int $qtyDelta, string $key, ?string $note = null, ?string $actor = null): ?JournalEntry
    {
        if ($qtyDelta === 0) {
            throw new \InvalidArgumentException('Adjustment quantity cannot be zero.');
        }
        $avg = $this->avgCost($productId, $variationId, $warehouseId);
        $cost = MoneyBridge::toMoney($avg)->multiply(abs($qtyDelta));
        return $this->move($qtyDelta < 0 ? 'damage' : 'adjustment', $productId, $variationId, $warehouseId, $qtyDelta, $avg, $qtyDelta < 0 ? Money::fromMinor(-$cost->amountMinor()) : $cost, $key, function ($c, $dims) use ($cost, $qtyDelta) {
            if ($cost->isZero()) {
                return [];
            }
            return $qtyDelta > 0
                ? [['account' => $c->accountCode('inventory'), 'debit' => $cost] + $dims, ['account' => $c->accountCode('other_expenses'), 'credit' => $cost] + $dims]
                : [['account' => $c->accountCode('other_expenses'), 'debit' => $cost, 'description' => 'Stock written down'] + $dims, ['account' => $c->accountCode('inventory'), 'credit' => $cost] + $dims];
        }, 'INVENTORY_ADJUSTMENT', $note, $actor, 'manual', null);
    }

    /**
     * COGS lines for a PLATFORM_OWNED order line at recognition — returned to the caller so they land in
     * the order's recognition journal; the inventory transaction is written by recordSale() after posting.
     * @return array{lines: array, cost: Money, unit_cost: string, short: bool}
     */
    public function cogsLinesFor(OrderItem $item, array $dims): array
    {
        $qty = max(1, (int) $item->order_quantity);
        [$vid, $wid] = [(int) ($item->variation_option_id ?? 0), 0];
        $val = $this->valuation((int) $item->product_id, $vid, $wid);
        $avg = $val ? (string) $val->avg_unit_cost : '0.0000';
        $short = !$val || (int) $val->qty_on_hand < $qty;
        $cost = MoneyBridge::toMoney($avg)->multiply($qty);
        $c = $this->config;
        $lines = $cost->isZero() ? [] : [
            ['account' => $c->accountCode('cogs'), 'debit' => $cost, 'description' => 'Cost of goods sold (platform-owned)'] + $dims,
            ['account' => $c->accountCode('inventory'), 'credit' => $cost, 'description' => 'Stock issued'] + $dims,
        ];
        return ['lines' => $lines, 'cost' => $cost, 'unit_cost' => $avg, 'short' => $short];
    }

    /** After the recognition journal posted: the stock-out movement for a platform-owned line. */
    public function recordSale(OrderItem $item, string $unitCost, Money $cost, JournalEntry $je, ?string $actor = null): void
    {
        $qty = max(1, (int) $item->order_quantity);
        $this->writeMovement('sale', (int) $item->product_id, (int) ($item->variation_option_id ?? 0), 0, -$qty, $unitCost, Money::fromMinor(-$cost->amountMinor()), 'sale:' . $item->id, $je->id, 'order_item', (int) $item->id, 'Sold on order #' . $item->order_id, $actor);
    }

    /** A received return of a platform-owned line: stock back in at the SALE's unit cost. */
    public function restockReturn(int $returnId, ?string $actor = null): ?JournalEntry
    {
        $r = DB::table('return_requests')->where('id', $returnId)->first();
        if (!$r) {
            return null;
        }
        $item = OrderItem::find($r->order_item_id);
        if (!$item || ($item->ownership_model ?: 'VENDOR_SUPPLIED') !== 'PLATFORM_OWNED') {
            return null;
        }
        $sale = DB::table('inventory_transactions')->where('idempotency_key', 'sale:' . $item->id)->first();
        $unitCost = $sale ? (string) $sale->unit_cost : $this->avgCost((int) $item->product_id, (int) ($item->variation_option_id ?? 0), 0);
        $qty = max(1, (int) $r->quantity);
        $cost = MoneyBridge::toMoney($unitCost)->multiply($qty);
        return $this->move('return', (int) $item->product_id, (int) ($item->variation_option_id ?? 0), 0, $qty, $unitCost, $cost, 'return:' . $returnId, function ($c, $dims) use ($cost) {
            if ($cost->isZero()) {
                return [];
            }
            return [['account' => $c->accountCode('inventory'), 'debit' => $cost, 'description' => 'Returned stock'] + $dims, ['account' => $c->accountCode('cogs'), 'credit' => $cost, 'description' => 'COGS reversed on return'] + $dims];
        }, 'RETURN_RECEIVED', 'Return #' . $returnId, $actor, 'return_request', $returnId, ['order_id' => $item->order_id, 'order_item_id' => $item->id]);
    }

    public function valuation(int $productId, int $variationId = 0, int $warehouseId = 0): ?object
    {
        return self::available() ? DB::table('inventory_valuations')->where(['product_id' => $productId, 'variation_option_id' => $variationId, 'warehouse_id' => $warehouseId])->first() : null;
    }

    public function avgCost(int $productId, int $variationId = 0, int $warehouseId = 0): string
    {
        $v = $this->valuation($productId, $variationId, $warehouseId);
        return $v ? number_format((float) $v->avg_unit_cost, 4, '.', '') : '0.0000';
    }

    // ── internals ────────────────────────────────────────────────────────────

    private function move(string $type, int $productId, int $variationId, int $warehouseId, int $qty, string $unitCost, Money $signedCost, string $key, callable $linesFor, string $sourceType, ?string $note, ?string $actor, ?string $refType, ?int $refId, array $extraDims = []): ?JournalEntry
    {
        if (!self::available() || !$this->config->enabled()) {
            return null;
        }
        if ($existing = DB::table('inventory_transactions')->where('idempotency_key', $key)->first()) {
            return $existing->journal_entry_id ? JournalEntry::find($existing->journal_entry_id) : null;
        }
        $dims = ['metadata' => ['product_id' => $productId, 'variation_option_id' => $variationId, 'warehouse_id' => $warehouseId, 'qty' => $qty]] + $extraDims;
        $lines = $linesFor($this->config, $dims);
        return DB::transaction(function () use ($type, $productId, $variationId, $warehouseId, $qty, $unitCost, $signedCost, $key, $lines, $sourceType, $note, $actor, $refType, $refId) {
            $je = $lines ? $this->journal->postLines($lines, [
                'source_type' => $sourceType, 'source_id' => $key, 'source_key' => $sourceType . ':' . $key,
                'reference_type' => $refType, 'reference_id' => $refId, 'description' => ucfirst($type) . ' ' . abs($qty) . ' × product #' . $productId . ($note ? ' — ' . $note : ''),
                'metadata' => ['product_id' => $productId, 'variation_option_id' => $variationId, 'warehouse_id' => $warehouseId, 'quantity' => $qty, 'unit_cost' => $unitCost], 'actor' => $actor,
            ]) : null;
            $this->writeMovement($type, $productId, $variationId, $warehouseId, $qty, $unitCost, $signedCost, $key, $je?->id, $refType, $refId, $note, $actor);
            return $je;
        });
    }

    /** The transaction row + the weighted-average valuation update, under a row lock. */
    private function writeMovement(string $type, int $productId, int $variationId, int $warehouseId, int $qty, string $unitCost, Money $signedCost, string $key, ?int $journalId, ?string $refType, ?int $refId, ?string $note, ?string $actor): void
    {
        if (!self::available()) {
            return;
        }
        DB::transaction(function () use ($type, $productId, $variationId, $warehouseId, $qty, $unitCost, $signedCost, $key, $journalId, $refType, $refId, $note, $actor) {
            if (DB::table('inventory_transactions')->where('idempotency_key', $key)->exists()) {
                return;
            }
            DB::table('inventory_transactions')->insert([
                'product_id' => $productId, 'variation_option_id' => $variationId, 'warehouse_id' => $warehouseId, 'type' => $type, 'quantity' => $qty,
                'unit_cost' => $unitCost, 'total_cost' => $signedCost->toDecimal(), 'reference_type' => $refType, 'reference_id' => $refId, 'journal_entry_id' => $journalId,
                'idempotency_key' => $key, 'note' => $note ? mb_substr($note, 0, 500) : null, 'created_by' => $actor, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
            ]);
            $where = ['product_id' => $productId, 'variation_option_id' => $variationId, 'warehouse_id' => $warehouseId];
            $v = DB::table('inventory_valuations')->where($where)->lockForUpdate()->first();
            if (!$v) {
                DB::table('inventory_valuations')->insert($where + ['qty_on_hand' => 0, 'avg_unit_cost' => 0, 'total_value' => 0, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()]);
                $v = (object) ['qty_on_hand' => 0, 'avg_unit_cost' => 0, 'total_value' => '0.00'];
            }
            $newQty = (int) $v->qty_on_hand + $qty;
            $newValue = MoneyBridge::toMoney((string) $v->total_value)->add($signedCost);
            if ($newQty <= 0) {
                // sold/written below zero at a cost the ledger did not hold → the value is what the journal moved
                $newQty = max(0, $newQty);
                $newValue = $newQty === 0 ? MoneyBridge::zero() : $newValue;
            }
            $avg = $newQty > 0 ? number_format($newValue->amountMinor() / 100 / $newQty, 4, '.', '') : '0.0000';
            DB::table('inventory_valuations')->where($where)->update(['qty_on_hand' => $newQty, 'avg_unit_cost' => $avg, 'total_value' => $newValue->toDecimal(), 'updated_at' => Carbon::now()]);
            AccountingAuditLog::record('inventory', $key, $type, ['qty' => (int) $v->qty_on_hand, 'value' => (string) $v->total_value], ['qty' => $newQty, 'value' => $newValue->toDecimal(), 'avg' => $avg], $note, $key, $actor);
        });
    }
}
