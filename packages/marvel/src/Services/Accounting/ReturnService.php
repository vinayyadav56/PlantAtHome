<?php

namespace Marvel\Services\Accounting;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Accounting\AccountingAuditLog;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderEvent;
use Marvel\Database\Models\OrderItem;
use Marvel\Database\Repositories\RefundRepository;

/**
 * Returns (spec §27): requested → approved → received → refunded (or rejected). Accounting
 * posts ONLY at the right events: nothing on request/approval; the refund (an item refund via
 * RefundService) on `refund`; the inventory restock for PLATFORM_OWNED lines on `received`
 * (P11). No double reversal — the refund is the one revenue/tax/payable reversal.
 */
class ReturnService
{
    public function request(int $orderItemId, int $quantity, ?string $reason, ?string $actor = null): object
    {
        $item = OrderItem::findOrFail($orderItemId);
        $order = Order::findOrFail($item->order_id);
        if ($quantity < 1 || $quantity > (int) $item->order_quantity) {
            throw new \InvalidArgumentException('Return quantity must be between 1 and ' . $item->order_quantity . '.');
        }
        $openQty = (int) DB::table('return_requests')->where('order_item_id', $item->id)->whereIn('status', ['requested', 'approved', 'received'])->sum('quantity');
        if ($openQty + $quantity > (int) $item->order_quantity) {
            throw new \InvalidArgumentException('A return is already open for these units.');
        }
        $id = DB::table('return_requests')->insertGetId([
            'order_id' => $order->id, 'order_item_id' => $item->id, 'customer_id' => $order->customer_id, 'quantity' => $quantity,
            'status' => 'requested', 'reason' => $reason ? mb_substr($reason, 0, 500) : null, 'requested_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
        ]);
        OrderEvent::record($order->id, 'return.requested', ['return_id' => $id, 'order_item_id' => $item->id, 'quantity' => $quantity], 'Return requested');
        return $this->find($id);
    }

    public function transition(int $returnId, string $to, ?string $actor = null, ?string $note = null): object
    {
        $allowed = ['approve' => ['requested', 'approved'], 'reject' => ['requested', 'approved', 'rejected'], 'receive' => ['approved', 'received']];
        return DB::transaction(function () use ($returnId, $to, $actor, $note, $allowed) {
            $r = DB::table('return_requests')->where('id', $returnId)->lockForUpdate()->first();
            if (!$r) {
                throw new \RuntimeException('Return #' . $returnId . ' not found.');
            }
            if (!isset($allowed[$to]) || !in_array($r->status, $allowed[$to], true)) {
                throw new \RuntimeException('Return #' . $returnId . ' is ' . $r->status . '; cannot ' . $to . '.');
            }
            $status = ['approve' => 'approved', 'reject' => 'rejected', 'receive' => 'received'][$to];
            if ($r->status === $status) {
                return $r; // idempotent
            }
            $upd = ['status' => $status, 'decided_by' => $actor, 'updated_at' => now(), 'notes' => trim(($r->notes ?? '') . ($note ? "\n" . $note : ''))];
            if ($status === 'approved') {
                $upd['approved_at'] = now();
            }
            if ($status === 'received') {
                $upd['received_at'] = now();
                // P11: PLATFORM_OWNED restock (DR Inventory / CR COGS) hooks here via InventoryLedgerService.
            }
            DB::table('return_requests')->where('id', $returnId)->update($upd);
            AccountingAuditLog::record('return_request', $returnId, $status, ['status' => $r->status], ['status' => $status], $note, null, $actor);
            OrderEvent::record($r->order_id, 'return.' . $status, ['return_id' => $returnId], 'Return ' . $status);
            return $this->find($returnId);
        });
    }

    /** Received → refunded: creates an ITEM refund through the normal refund path (approval posts it). */
    public function refund(int $returnId, ?string $method, ?string $actor = null): object
    {
        $r = DB::table('return_requests')->where('id', $returnId)->first();
        if ($r && $r->refund_id) {
            return $r; // idempotent (also after it closed as 'refunded')
        }
        if (!$r || $r->status !== 'received') {
            throw new \RuntimeException('Only a received return can be refunded.');
        }
        $order = Order::findOrFail($r->order_id);
        $refund = app(RefundRepository::class)->createSliced($order, [
            'order_id' => $order->id, 'customer_id' => $order->customer_id, 'title' => 'Return #' . $returnId, 'description' => $r->reason,
        ], 'items', [['order_item_id' => $r->order_item_id, 'quantity' => $r->quantity]], null, $method);
        DB::table('return_requests')->where('id', $returnId)->update(['refund_id' => $refund->id, 'updated_at' => now()]);
        OrderEvent::record($order->id, 'return.refund_requested', ['return_id' => $returnId, 'refund_id' => $refund->id], 'Return refund requested');
        return $this->find($returnId);
    }

    /** Called by the refund approval path so the return closes when its refund is approved. */
    public static function markRefunded(int $refundId): void
    {
        try {
            DB::table('return_requests')->where('refund_id', $refundId)->where('status', 'received')->update(['status' => 'refunded', 'refunded_at' => now(), 'updated_at' => now()]);
        } catch (\Throwable $e) {
            // returns table may not exist mid-migration
        }
    }

    private function find(int $id): object
    {
        return DB::table('return_requests')->where('id', $id)->first();
    }
}
