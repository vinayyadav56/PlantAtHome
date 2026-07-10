<?php

namespace Tests\Feature\Sales;

use App\Modules\Identity\Database\Seeders\IdentityAccessSeeder;
use App\Modules\Inventory\Application\InventoryService;

/**
 * Phase 8 acceptance (end-to-end): add configured items from TWO vendors →
 * checkout → reserve → pay → ONE parent order + TWO sub-orders; each vendor sees
 * only its own; a refund restocks inventory. Plus atomicity + idempotency.
 */
class SalesFlowTest extends SalesTestCase
{
    private const NA = IdentityAccessSeeder::NURSERY_A;
    private const NB = IdentityAccessSeeder::NURSERY_B;

    private array $s; // seeded uuids

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->seedCatalogAndStock();
    }

    private function admin(): array
    {
        return $this->bearer($this->accessToken('admin@plantathome.test'));
    }

    private function customer(): array
    {
        return $this->bearer($this->accessToken('customer@plantathome.test'));
    }

    /** Two products, priced + stocked for nursery A and nursery B respectively. */
    private function seedCatalogAndStock(): array
    {
        $admin = $this->admin();
        $mk = function (string $name, int $price, string $nursery) use ($admin) {
            $p = $this->postJson('/api/v1/catalog/products', ['name' => $name, 'status' => 'published', 'variants' => [['size_code' => 'M']]], $admin);
            $variant = $p->json('data.variants.0.uuid');
            $this->postJson('/api/v1/pricing/base-prices', ['sellable_type' => 'variant', 'sellable_uuid' => $variant, 'amount' => $price], $admin)->assertStatus(201);
            $this->putJson('/api/v1/inventory/stock', ['sellable_type' => 'variant', 'sellable_uuid' => $variant, 'nursery_id' => $nursery, 'qty_on_hand' => 5], $admin)->assertStatus(200);

            return $variant;
        };

        return ['vA' => $mk('Palm A', 100, self::NA), 'vB' => $mk('Palm B', 200, self::NB)];
    }

    private function available(string $variant, string $nursery): int
    {
        return $this->app->make(InventoryService::class)->available('variant', $variant, $nursery);
    }

    private function addToCart(string $variant, string $nursery): void
    {
        $this->postJson('/api/v1/cart/items', ['variant_uuid' => $variant, 'nursery_id' => $nursery, 'qty' => 1], $this->customer())
            ->assertStatus(201);
    }

    /* ── the end-to-end acceptance ─────────────────────────────────────────── */

    public function test_two_vendor_checkout_produces_one_parent_and_two_sub_orders(): void
    {
        $this->addToCart($this->s['vA'], self::NA);
        $this->addToCart($this->s['vB'], self::NB);

        // Cart grand total = 100 + 200.
        $this->getJson('/api/v1/cart', $this->customer())->assertJsonPath('data.grand_total_minor', 30000);

        // Start checkout → payment_pending, reserves stock.
        $checkout = $this->postJson('/api/v1/checkout', [], $this->customer())
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'payment_pending');
        $this->assertSame(4, $this->available($this->s['vA'], self::NA)); // 5 − 1 reserved
        $this->assertSame(4, $this->available($this->s['vB'], self::NB));

        // Pay → ONE parent, TWO sub-orders (one per vendor).
        $order = $this->postJson("/api/v1/checkout/{$checkout->json('data.checkout_uuid')}/pay", ['success' => true], $this->customer())
            ->assertStatus(200);
        $orderUuid = $order->json('data.order.uuid');
        $this->assertCount(2, $order->json('data.order.sub_orders'));

        $nurseries = array_column($order->json('data.order.sub_orders'), 'nursery_id');
        $this->assertEqualsCanonicalizing([self::NA, self::NB], $nurseries);

        // On-hand deducted (reserved committed).
        $this->assertSame(4, $this->available($this->s['vA'], self::NA));

        // Customer sees the full parent order (both sub-orders).
        $this->getJson("/api/v1/orders/{$orderUuid}", $this->customer())
            ->assertStatus(200)->assertJsonCount(2, 'data.sub_orders');
    }

    public function test_each_vendor_sees_only_its_own_sub_order(): void
    {
        $this->addToCart($this->s['vA'], self::NA);
        $this->addToCart($this->s['vB'], self::NB);
        $checkout = $this->postJson('/api/v1/checkout', [], $this->customer())->json('data.checkout_uuid');
        $this->postJson("/api/v1/checkout/{$checkout}/pay", ['success' => true], $this->customer())->assertStatus(200);

        $ownerA = $this->bearer($this->accessToken('owner.a@plantathome.test'));
        $ownerB = $this->bearer($this->accessToken('owner.b@plantathome.test'));

        $aQueue = $this->getJson('/api/v1/nursery/sub-orders', $ownerA)->assertStatus(200)->json('data');
        $bQueue = $this->getJson('/api/v1/nursery/sub-orders', $ownerB)->assertStatus(200)->json('data');

        $this->assertCount(1, $aQueue);
        $this->assertCount(1, $bQueue);
        $this->assertSame(self::NA, $aQueue[0]['nursery_id']);
        $this->assertSame(self::NB, $bQueue[0]['nursery_id']);

        // Nursery A cannot act on nursery B's sub-order.
        $this->postJson("/api/v1/nursery/sub-orders/{$bQueue[0]['uuid']}/transition", ['to' => 'confirmed'], $ownerA)
            ->assertStatus(404);
    }

    public function test_a_refund_restocks_inventory(): void
    {
        $this->addToCart($this->s['vA'], self::NA);
        $checkout = $this->postJson('/api/v1/checkout', [], $this->customer())->json('data.checkout_uuid');
        $order = $this->postJson("/api/v1/checkout/{$checkout}/pay", ['success' => true], $this->customer())->assertStatus(200);
        $subUuid = $order->json('data.order.sub_orders.0.uuid');

        $this->assertSame(4, $this->available($this->s['vA'], self::NA)); // sold 1 of 5

        $ownerA = $this->bearer($this->accessToken('owner.a@plantathome.test'));
        // placed → cancelled → refunded (restocks).
        $this->postJson("/api/v1/nursery/sub-orders/{$subUuid}/transition", ['to' => 'cancelled'], $ownerA)->assertStatus(200);
        $this->postJson("/api/v1/nursery/sub-orders/{$subUuid}/refund", [], $ownerA)
            ->assertStatus(200)->assertJsonPath('data.status', 'refunded');

        $this->assertSame(5, $this->available($this->s['vA'], self::NA)); // restocked
    }

    public function test_an_illegal_sub_order_transition_is_rejected(): void
    {
        $this->addToCart($this->s['vA'], self::NA);
        $checkout = $this->postJson('/api/v1/checkout', [], $this->customer())->json('data.checkout_uuid');
        $order = $this->postJson("/api/v1/checkout/{$checkout}/pay", ['success' => true], $this->customer())->assertStatus(200);
        $subUuid = $order->json('data.order.sub_orders.0.uuid');
        $ownerA = $this->bearer($this->accessToken('owner.a@plantathome.test'));

        // placed → delivered is not a legal edge.
        $this->postJson("/api/v1/nursery/sub-orders/{$subUuid}/transition", ['to' => 'delivered'], $ownerA)
            ->assertStatus(409)->assertJsonPath('errors.0.code', 'ILLEGAL_TRANSITION');
    }

    public function test_paying_is_idempotent(): void
    {
        $this->addToCart($this->s['vA'], self::NA);
        $checkout = $this->postJson('/api/v1/checkout', [], $this->customer())->json('data.checkout_uuid');

        $first = $this->postJson("/api/v1/checkout/{$checkout}/pay", ['success' => true], $this->customer())->assertStatus(200)->json('data.order.uuid');
        $second = $this->postJson("/api/v1/checkout/{$checkout}/pay", ['success' => true], $this->customer())->assertStatus(200)->json('data.order.uuid');

        $this->assertSame($first, $second);         // same order, no double-charge
        $this->assertSame(4, $this->available($this->s['vA'], self::NA)); // deducted once
    }

    public function test_checkout_fails_cleanly_when_out_of_stock(): void
    {
        // Drain nursery A stock to 0, then try to checkout its item.
        $this->putJson('/api/v1/inventory/stock', ['sellable_type' => 'variant', 'sellable_uuid' => $this->s['vA'], 'nursery_id' => self::NA, 'qty_on_hand' => 0], $this->admin())->assertStatus(200);
        $this->addToCart($this->s['vA'], self::NA);

        $this->postJson('/api/v1/checkout', [], $this->customer())
            ->assertStatus(409)->assertJsonPath('errors.0.code', 'INSUFFICIENT_STOCK');
    }

    /* ── hardening regressions (adversarial-review round) ──────────────────── */

    /**
     * A checkout paid after its reservation TTL lapsed (and the sweep returned
     * the stock to the pool) must FAIL CLOSED — never mint a paid order on stock
     * we no longer hold (would oversell).
     */
    public function test_pay_after_reservation_expiry_fails_closed(): void
    {
        $this->addToCart($this->s['vA'], self::NA);
        $checkout = $this->postJson('/api/v1/checkout', [], $this->customer())->json('data.checkout_uuid');
        $this->assertSame(4, $this->available($this->s['vA'], self::NA)); // reserved

        // Simulate the TTL lapsing, then run the release-expired sweep.
        \App\Modules\Inventory\Infrastructure\Models\Reservation::where('checkout_session_id', $checkout)
            ->update(['expires_at' => now()->subMinutes(20)]);
        $released = $this->app->make(InventoryService::class)->releaseExpired();
        $this->assertGreaterThan(0, $released);
        $this->assertSame(5, $this->available($this->s['vA'], self::NA)); // returned to pool

        // Paying now must fail — no paid order, no re-deduction.
        $this->postJson("/api/v1/checkout/{$checkout}/pay", ['success' => true], $this->customer())
            ->assertStatus(402);
        $this->assertSame(5, $this->available($this->s['vA'], self::NA)); // never committed
    }

    /**
     * The generic transition endpoint must NOT be a back door to 'refunded' —
     * that path skips the restock + OrderRefunded event that only the refund
     * action performs. Callers are forced through /refund.
     */
    public function test_transition_directly_to_refunded_is_rejected(): void
    {
        $this->addToCart($this->s['vA'], self::NA);
        $checkout = $this->postJson('/api/v1/checkout', [], $this->customer())->json('data.checkout_uuid');
        $order = $this->postJson("/api/v1/checkout/{$checkout}/pay", ['success' => true], $this->customer())->assertStatus(200);
        $subUuid = $order->json('data.order.sub_orders.0.uuid');
        $ownerA = $this->bearer($this->accessToken('owner.a@plantathome.test'));

        // Cancel first so 'refunded' would be a legal state-machine edge…
        $this->postJson("/api/v1/nursery/sub-orders/{$subUuid}/transition", ['to' => 'cancelled'], $ownerA)->assertStatus(200);
        // …but the generic transition still refuses it.
        $this->postJson("/api/v1/nursery/sub-orders/{$subUuid}/transition", ['to' => 'refunded'], $ownerA)
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'USE_REFUND_ACTION');

        $this->assertSame(4, $this->available($this->s['vA'], self::NA)); // NOT restocked
    }

    /**
     * A second refund on an already-refunded sub-order is rejected (refunded has
     * no outgoing edge), so inventory is restocked exactly once — the sequential
     * guard behind the concurrency lock fix.
     */
    public function test_a_second_refund_is_rejected_and_restocks_once(): void
    {
        $this->addToCart($this->s['vA'], self::NA);
        $checkout = $this->postJson('/api/v1/checkout', [], $this->customer())->json('data.checkout_uuid');
        $order = $this->postJson("/api/v1/checkout/{$checkout}/pay", ['success' => true], $this->customer())->assertStatus(200);
        $subUuid = $order->json('data.order.sub_orders.0.uuid');
        $ownerA = $this->bearer($this->accessToken('owner.a@plantathome.test'));

        $this->postJson("/api/v1/nursery/sub-orders/{$subUuid}/transition", ['to' => 'cancelled'], $ownerA)->assertStatus(200);
        $this->postJson("/api/v1/nursery/sub-orders/{$subUuid}/refund", [], $ownerA)->assertStatus(200);
        $this->assertSame(5, $this->available($this->s['vA'], self::NA)); // restocked once

        $this->postJson("/api/v1/nursery/sub-orders/{$subUuid}/refund", [], $ownerA)
            ->assertStatus(409)->assertJsonPath('errors.0.code', 'ILLEGAL_TRANSITION');
        $this->assertSame(5, $this->available($this->s['vA'], self::NA)); // still 5, not 6
    }
}
