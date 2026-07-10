<?php

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Application\InventoryService;
use App\Modules\Inventory\Domain\ReservationStatus;
use App\Modules\Inventory\Domain\SellableType;
use App\Modules\Inventory\Infrastructure\Models\InventoryItem;
use App\Modules\Inventory\Infrastructure\Models\Reservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Phase 5 acceptance: reserve is atomic (no oversell — exactly one caller can
 * take the last unit), reservation expiry releases stock, and movements
 * reconcile to on-hand.
 */
class InventoryTest extends InventoryTestCase
{
    private string $nurseryA = '11111111-1111-1111-1111-111111111111';
    private string $sku;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sku = (string) Str::uuid();
    }

    private function admin(): array
    {
        return $this->bearer($this->accessToken('admin@plantathome.test'));
    }

    private function service(): InventoryService
    {
        return $this->app->make(InventoryService::class);
    }

    private function setStock(int $qty): void
    {
        $this->putJson('/api/v1/inventory/stock', [
            'sellable_type' => SellableType::VARIANT, 'sellable_uuid' => $this->sku,
            'nursery_id' => $this->nurseryA, 'qty_on_hand' => $qty,
        ], $this->admin())->assertStatus(200);
    }

    private function reserve(int $qty, string $session, ?array $token = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/inventory/reservations', [
            'checkout_session_id' => $session,
            'items' => [['sellable_type' => SellableType::VARIANT, 'sellable_uuid' => $this->sku, 'nursery_id' => $this->nurseryA, 'qty' => $qty]],
        ], $token ?? $this->admin());
    }

    private function available(): int
    {
        return $this->getJson("/api/v1/inventory/availability?sellable_type=variant&sellable_uuid={$this->sku}&nursery_id={$this->nurseryA}")
            ->json('data.available');
    }

    /* ── acceptance ────────────────────────────────────────────────────────── */

    public function test_set_stock_then_read_availability(): void
    {
        $this->setStock(5);
        $this->assertSame(5, $this->available());
    }

    public function test_the_last_unit_can_be_reserved_only_once(): void
    {
        $this->setStock(1);

        $this->reserve(1, (string) Str::uuid())->assertStatus(201);
        $this->assertSame(0, $this->available());

        // A second reservation for the (now-gone) unit is a clean 409 — no oversell.
        $this->reserve(1, (string) Str::uuid())
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'INSUFFICIENT_STOCK');
    }

    public function test_commit_deducts_on_hand_and_release_returns_reserved(): void
    {
        $this->setStock(3);
        $session = (string) Str::uuid();
        $this->reserve(2, $session)->assertStatus(201);
        $this->assertSame(1, $this->available());

        $this->postJson("/api/v1/inventory/reservations/{$session}/commit", [], $this->admin())
            ->assertStatus(200)->assertJsonPath('data.committed', 1);

        $item = InventoryItem::where('sellable_uuid', $this->sku)->first();
        $this->assertSame(1, $item->qty_on_hand);
        $this->assertSame(0, $item->qty_reserved);

        // A fresh reservation then released returns to available.
        $s2 = (string) Str::uuid();
        $this->reserve(1, $s2)->assertStatus(201);
        $this->assertSame(0, $this->available());
        $this->postJson("/api/v1/inventory/reservations/{$s2}/release", [], $this->admin())
            ->assertStatus(200)->assertJsonPath('data.released', 1);
        $this->assertSame(1, $this->available());
    }

    public function test_expired_reservations_are_swept_back_to_available(): void
    {
        $this->setStock(1);
        $session = (string) Str::uuid();
        $this->reserve(1, $session)->assertStatus(201);
        $this->assertSame(0, $this->available());

        // Force the reservation past its TTL, then sweep.
        Reservation::where('checkout_session_id', $session)->update(['expires_at' => Carbon::now()->subMinute()]);
        $swept = $this->service()->releaseExpired();

        $this->assertSame(1, $swept);
        $this->assertSame(1, $this->available());
        $this->assertDatabaseHas('inv_reservations', ['checkout_session_id' => $session, 'status' => ReservationStatus::EXPIRED]);
    }

    public function test_movements_reconcile_to_on_hand(): void
    {
        $this->setStock(5);                 // +5
        $this->setStock(8);                 // +3
        $session = (string) Str::uuid();
        $this->reserve(3, $session)->assertStatus(201);
        $this->postJson("/api/v1/inventory/reservations/{$session}/commit", [], $this->admin())->assertStatus(200); // -3

        $item = InventoryItem::where('sellable_uuid', $this->sku)->first();
        $this->assertSame(5, $item->qty_on_hand);
        $this->assertSame(5, $this->service()->ledgerBalance($item)); // 5 + 3 − 3
    }

    public function test_reserve_is_all_or_nothing_across_lines(): void
    {
        $this->setStock(5); // this SKU has stock
        $otherSku = (string) Str::uuid(); // not stocked

        $res = $this->postJson('/api/v1/inventory/reservations', [
            'checkout_session_id' => (string) Str::uuid(),
            'items' => [
                ['sellable_type' => 'variant', 'sellable_uuid' => $this->sku, 'nursery_id' => $this->nurseryA, 'qty' => 1],
                ['sellable_type' => 'variant', 'sellable_uuid' => $otherSku, 'nursery_id' => $this->nurseryA, 'qty' => 1],
            ],
        ], $this->admin());

        $res->assertStatus(409);
        // The in-stock line's reserved count was rolled back — nothing held.
        $this->assertSame(5, $this->available());
        $this->assertDatabaseCount('inv_reservations', 0);
    }

    /* ── authorization ─────────────────────────────────────────────────────── */

    public function test_a_nursery_cannot_set_another_nurserys_stock(): void
    {
        $ownerA = $this->bearer($this->accessToken('owner.a@plantathome.test'));
        $nurseryB = '22222222-2222-2222-2222-222222222222';

        $this->putJson('/api/v1/inventory/stock', [
            'sellable_type' => 'variant', 'sellable_uuid' => $this->sku,
            'nursery_id' => $nurseryB, 'qty_on_hand' => 10,
        ], $ownerA)->assertStatus(403);
    }

    public function test_stock_write_requires_authorization(): void
    {
        $customer = $this->bearer($this->accessToken('customer@plantathome.test'));
        $this->putJson('/api/v1/inventory/stock', [
            'sellable_type' => 'variant', 'sellable_uuid' => $this->sku,
            'nursery_id' => $this->nurseryA, 'qty_on_hand' => 10,
        ], $customer)->assertStatus(403);
    }
}
