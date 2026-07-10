<?php

namespace App\Modules\Sales\Application;

use App\Modules\Inventory\Application\InventoryService;
use App\Modules\Inventory\Domain\SellableType;
use App\Modules\Sales\Domain\Events\OrderRefunded;
use App\Modules\Sales\Domain\StateMachine;
use App\Modules\Sales\Domain\Status;
use App\Modules\Sales\Infrastructure\Models\Order;
use App\Modules\Sales\Infrastructure\Models\StatusHistory;
use App\Modules\Sales\Infrastructure\Models\SubOrder;
use App\Shared\Application\DomainActionException;
use App\Shared\Events\EventPublisher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * Order reads + sub-order lifecycle (Section 8 & 10). The customer sees ONE
 * parent order; a nursery only ever sees its own sub-orders. Sub-order
 * transitions are state-machine-checked; the parent status is derived (never set
 * directly). Refunding a sub-order restocks its inventory.
 */
class OrderService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly InventoryService $inventory,
        private readonly EventPublisher $events,
    ) {
    }

    /** Parent order (full view) — customer-owned or admin. */
    public function customerOrder(string $orderUuid, ?string $customerUuid, bool $isAdmin): Order
    {
        $order = Order::where('uuid', $orderUuid)->with('subOrders.items')->first();
        if (! $order) {
            throw DomainActionException::notFound('Order not found.', 'ORDER_NOT_FOUND');
        }
        if (! $isAdmin && $order->customer_uuid !== $customerUuid) {
            throw DomainActionException::notFound('Order not found.', 'ORDER_NOT_FOUND'); // don't leak existence
        }

        return $order;
    }

    /** A nursery's own sub-orders (never the parent). @return \Illuminate\Support\Collection<int,SubOrder> */
    public function nurserySubOrders(string $nurseryId)
    {
        return SubOrder::where('nursery_id', $nurseryId)->with('items')->latest('id')->get();
    }

    public function nurserySubOrder(string $subOrderUuid, ?string $nurseryId, bool $isAdmin): SubOrder
    {
        $sub = SubOrder::where('uuid', $subOrderUuid)->with('items')->first();
        if (! $sub || (! $isAdmin && $sub->nursery_id !== $nurseryId)) {
            throw DomainActionException::notFound('Sub-order not found.', 'SUB_ORDER_NOT_FOUND');
        }

        return $sub;
    }

    /** Move a sub-order along its lifecycle (state-machine checked). */
    public function transitionSubOrder(SubOrder $sub, string $to, ?string $actor): SubOrder
    {
        return $this->db->transaction(function () use ($sub, $to, $actor) {
            StateMachine::assert('sub_order', $sub->status, $to);
            $from = $sub->status;
            $sub->status = $to;
            $sub->save();
            $this->log('sub_order', $sub->uuid, $from, $to, $actor);
            $this->deriveParentStatus($sub->parent_order_id);

            return $sub;
        });
    }

    /** Refund a (cancelled/rejected/returned) sub-order and restock its inventory. */
    public function refundSubOrder(SubOrder $sub, ?string $actor): SubOrder
    {
        return $this->db->transaction(function () use ($sub, $actor) {
            StateMachine::assert('sub_order', $sub->status, Status::SUB_REFUNDED);

            foreach ($sub->items as $item) {
                $this->inventory->restock(SellableType::VARIANT, $item->variant_uuid, $sub->nursery_id, (int) $item->qty, 'sub_order', $sub->uuid);
                foreach (($item->config_snapshot['options'] ?? []) as $optionUuid) {
                    $this->inventory->restock(SellableType::OPTION, $optionUuid, $sub->nursery_id, (int) $item->qty, 'sub_order', $sub->uuid);
                }
            }

            $from = $sub->status;
            $sub->status = Status::SUB_REFUNDED;
            $sub->save();
            $this->log('sub_order', $sub->uuid, $from, Status::SUB_REFUNDED, $actor);
            $this->deriveParentStatus($sub->parent_order_id);

            $this->events->publish(new OrderRefunded($sub->uuid, $sub->nursery_id, $sub->totals ?? []));

            return $sub;
        });
    }

    /** Parent status is a ROLLUP of its sub-orders — never set directly. */
    private function deriveParentStatus(int $parentOrderId): void
    {
        $order = Order::with('subOrders')->find($parentOrderId);
        if (! $order) {
            return;
        }
        $statuses = $order->subOrders->pluck('status');

        $terminalGone = [Status::SUB_CANCELLED, Status::SUB_REJECTED, Status::SUB_REFUNDED, Status::SUB_RETURNED];

        if ($statuses->every(fn ($s) => $s === Status::SUB_REFUNDED)) {
            $status = Status::ORDER_REFUNDED;
        } elseif ($statuses->every(fn ($s) => in_array($s, $terminalGone, true))) {
            $status = Status::ORDER_CANCELLED;
        } elseif ($statuses->every(fn ($s) => $s === Status::SUB_DELIVERED)) {
            $status = Status::ORDER_COMPLETED;
        } elseif ($statuses->contains(Status::SUB_DELIVERED) || $statuses->contains(fn ($s) => in_array($s, $terminalGone, true))) {
            $status = Status::ORDER_PARTIALLY_FULFILLED;
        } else {
            $status = Status::ORDER_PROCESSING;
        }

        $order->update(['status' => $status]);
    }

    private function log(string $entityType, string $uuid, ?string $from, string $to, ?string $actor): void
    {
        StatusHistory::create([
            'entity_type' => $entityType, 'entity_uuid' => $uuid,
            'from_status' => $from, 'to_status' => $to, 'actor' => $actor, 'at' => Carbon::now(),
        ]);
    }
}
