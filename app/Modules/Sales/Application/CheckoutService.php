<?php

namespace App\Modules\Sales\Application;

use App\Modules\Catalog\Infrastructure\Models\Product as CatalogProduct;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant as CatalogVariant;
use App\Modules\Configuration\Application\ConfigurationService;
use App\Modules\Inventory\Application\InventoryService;
use App\Modules\Inventory\Domain\SellableType;
use App\Modules\Sales\Domain\Events\OrderPlaced;
use App\Modules\Sales\Domain\StateMachine;
use App\Modules\Sales\Domain\Status;
use App\Modules\Sales\Infrastructure\Models\Cart;
use App\Modules\Sales\Infrastructure\Models\CheckoutSession;
use App\Modules\Sales\Infrastructure\Models\Order;
use App\Modules\Sales\Infrastructure\Models\PaymentIntent;
use App\Modules\Sales\Infrastructure\Models\StatusHistory;
use App\Shared\Application\DomainActionException;
use App\Shared\Events\EventPublisher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;

/**
 * Checkout orchestration (Section 8). Runs the state machine:
 *   initiated → validated → reserved → payment_pending → (pay) → paid →
 *   orders_created → done, with failure paths that release reservations.
 * On payment success it commits inventory and splits the cart into ONE parent
 * order + one sub-order per vendor (vendor-hidden). Idempotent per checkout: a
 * second pay on a completed checkout returns the same order.
 */
class CheckoutService
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ConfigurationService $config,
        private readonly InventoryService $inventory,
        private readonly EventPublisher $events,
    ) {
    }

    /** Start checkout: validate → reserve → create payment intent. */
    public function start(Cart $cart, ?string $customerUuid, array $address = []): CheckoutSession
    {
        if ($cart->items()->count() === 0) {
            throw DomainActionException::unprocessable('Cannot checkout an empty cart.', 'EMPTY_CART');
        }

        return $this->db->transaction(function () use ($cart, $customerUuid, $address) {
            $session = CheckoutSession::create([
                'cart_id'          => $cart->id,
                'customer_uuid'    => $customerUuid,
                'status'           => Status::CHECKOUT_INITIATED,
                'address_snapshot' => $address,
            ]);

            // 1) validate every line's configuration server-side.
            foreach ($cart->items as $item) {
                $selection = $item->config_snapshot['selection'] ?? [];
                $result = $this->config->validateSelection($item->variant_uuid, $selection, $cart->city, $item->nursery_id);
                if ($result->fails()) {
                    $this->transition($session, Status::CHECKOUT_FAILED);
                    throw new InvalidSelectionException($result->violations());
                }
            }
            $this->transition($session, Status::CHECKOUT_VALIDATED);

            // 2) reserve stock for every variant + option SKU (atomic, no oversell).
            $lines = [];
            foreach ($cart->items as $item) {
                $lines[] = ['sellable_type' => SellableType::VARIANT, 'sellable_uuid' => $item->variant_uuid, 'nursery_id' => $item->nursery_id, 'qty' => $item->qty];
                foreach (($item->config_snapshot['options'] ?? []) as $optionUuid) {
                    $lines[] = ['sellable_type' => SellableType::OPTION, 'sellable_uuid' => $optionUuid, 'nursery_id' => $item->nursery_id, 'qty' => $item->qty];
                }
            }
            try {
                $this->inventory->reserve($lines, $session->uuid);
            } catch (DomainActionException $e) {
                $this->transition($session, Status::CHECKOUT_FAILED);
                throw $e;
            }
            $session->inventory_session = $session->uuid;
            $session->save();
            $this->transition($session, Status::CHECKOUT_RESERVED);

            // 3) re-price + totals snapshot (frozen at order creation).
            $totalMinor = (int) $cart->items->sum(fn ($i) => (int) ($i->price_snapshot['total']['amount_minor'] ?? 0));
            $session->totals = ['grand_total' => ['amount_minor' => $totalMinor, 'currency' => 'INR']];
            $session->save();

            // 4) create the payment intent.
            PaymentIntent::create([
                'checkout_id' => $session->id,
                'amount'      => $totalMinor / 100,
                'currency'    => 'INR',
                'status'      => Status::PAY_CREATED,
            ]);
            $this->transition($session, Status::CHECKOUT_PAYMENT_PENDING);

            return $session->fresh();
        });
    }

    /**
     * Complete (or fail) payment. Idempotent: paying an already-completed
     * checkout returns its existing order.
     */
    public function pay(CheckoutSession $session, bool $success, ?string $idempotencyKey = null): ?Order
    {
        // Idempotency: already completed ⇒ return the existing order.
        if ($session->status === Status::CHECKOUT_DONE) {
            return Order::where('checkout_id', $session->id)->first();
        }
        if ($session->status !== Status::CHECKOUT_PAYMENT_PENDING) {
            throw DomainActionException::conflict('Checkout is not awaiting payment.', 'NOT_PAYABLE');
        }

        return $this->db->transaction(function () use ($session, $success, $idempotencyKey) {
            $intent = PaymentIntent::where('checkout_id', $session->id)->latest('id')->first();
            $this->transitionEntity('payment', $intent, Status::PAY_PROCESSING);

            if (! $success) {
                $this->transitionEntity('payment', $intent, Status::PAY_FAILED);
                $this->inventory->release((string) $session->inventory_session);
                $this->transition($session, Status::CHECKOUT_FAILED);

                return null;
            }

            if ($idempotencyKey) {
                $intent->idempotency_key = $idempotencyKey;
            }
            $intent->provider_ref = 'sim_'.$intent->uuid;
            $this->transitionEntity('payment', $intent, Status::PAY_SUCCEEDED);

            $this->transition($session, Status::CHECKOUT_PAID);

            // Deduct reserved stock, then split into parent + per-vendor sub-orders.
            $this->inventory->commit((string) $session->inventory_session);
            $order = $this->splitOrders($session);

            $this->transition($session, Status::CHECKOUT_ORDERS_CREATED);
            $this->transition($session, Status::CHECKOUT_DONE);

            return $order;
        });
    }

    private function splitOrders(CheckoutSession $session): Order
    {
        $cart = $session->cart()->with('items')->first();

        $order = Order::create([
            'customer_uuid'    => $session->customer_uuid,
            'checkout_id'      => $session->id,
            'address_snapshot' => $session->address_snapshot,
            'totals'           => $session->totals,
            'status'           => Status::ORDER_PROCESSING,
            'delivery_fee'     => 0,
        ]);

        $byNursery = $cart->items->groupBy('nursery_id');
        $subSummaries = [];
        foreach ($byNursery as $nurseryId => $items) {
            $subtotal = (int) $items->sum(fn ($i) => (int) ($i->price_snapshot['total']['amount_minor'] ?? 0));
            $sub = $order->subOrders()->create([
                'nursery_id' => $nurseryId,
                'status'     => Status::SUB_PLACED,
                'totals'     => ['subtotal' => ['amount_minor' => $subtotal, 'currency' => 'INR']],
            ]);
            $this->log('sub_order', $sub->uuid, null, Status::SUB_PLACED, $session->customer_uuid);

            foreach ($items as $item) {
                $variant = CatalogVariant::where('uuid', $item->variant_uuid)->first();
                $product = $variant ? CatalogProduct::find($variant->product_id) : null;
                $sub->items()->create([
                    'variant_uuid'     => $item->variant_uuid,
                    'product_snapshot' => $product ? ['uuid' => $product->uuid, 'name' => $product->name, 'slug' => $product->slug] : null,
                    'variant_snapshot' => $variant ? ['uuid' => $variant->uuid, 'sku' => $variant->sku, 'size_code' => $variant->size_code] : null,
                    'config_snapshot'  => $item->config_snapshot,
                    'price_snapshot'   => $item->price_snapshot,
                    'weight_grams'     => $variant?->weight_grams,
                    'qty'              => $item->qty,
                ]);
            }
            $subSummaries[] = ['uuid' => $sub->uuid, 'nursery_id' => $nurseryId];
        }

        $cart->update(['status' => 'checked_out']);

        $this->events->publish(new OrderPlaced($order->uuid, $session->customer_uuid, $subSummaries, $session->totals ?? []));

        return $order->fresh('subOrders.items');
    }

    /* ── transitions ───────────────────────────────────────────────────────── */

    private function transition(CheckoutSession $session, string $to): void
    {
        StateMachine::assert('checkout', $session->status, $to);
        $from = $session->status;
        $session->status = $to;
        $session->save();
        $this->log('checkout', $session->uuid, $from, $to, $session->customer_uuid);
    }

    private function transitionEntity(string $type, PaymentIntent $intent, string $to): void
    {
        StateMachine::assert($type, $intent->status, $to);
        $from = $intent->status;
        $intent->status = $to;
        $intent->save();
        $this->log($type, $intent->uuid, $from, $to, null);
    }

    private function log(string $entityType, string $uuid, ?string $from, string $to, ?string $actor): void
    {
        StatusHistory::create([
            'entity_type' => $entityType, 'entity_uuid' => $uuid,
            'from_status' => $from, 'to_status' => $to, 'actor' => $actor, 'at' => Carbon::now(),
        ]);
    }
}
