<?php

namespace Tests\Feature\Nursery;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The "Delivery & fulfilment" step of the admin vendor wizard, on a V2-backed environment.
 *
 * delivery_mode / self_delivery lived only on legacy `shops`. V2's update had no columns, no
 * fillable entries and no validation rules for them, so on staging — where the wizard's Update
 * goes to V2 — the whole step silently saved nothing, while saving fine on production where
 * Update is still the legacy PUT. The inverse of the coverage bug, on the same page.
 *
 * And the fulfilment engine (MatchingService, OrderItemService, MetricsService) reads
 * shops.delivery_mode, so the V2 row alone is not enough: the value has to reach the legacy
 * row, or the vendor's choice never affects a single order.
 */
class NurseryFulfilmentTest extends NurseryTestCase
{
    private function legacyShopsTable(): void
    {
        if (!Schema::hasTable('shops')) {
            Schema::create('shops', function ($t) {
                $t->id();
                $t->string('name')->nullable();
                $t->string('delivery_mode', 16)->default('platform');
                $t->json('self_delivery')->nullable();
                $t->timestamps();
            });
        }
    }

    public function test_fulfilment_fields_persist_on_a_v2_update_and_round_trip(): void
    {
        $admin = $this->bearer($this->accessToken('admin@plantathome.test'));
        $uuid = $this->postJson('/api/v1/nurseries', ['name' => 'Self Deliverer', 'slug' => 'self-deliverer'], $admin)
            ->assertStatus(201)->json('data.uuid');

        $this->patchJson("/api/v1/nurseries/{$uuid}", [
            'delivery_mode' => 'self',
            'self_delivery' => ['contact_name' => 'Ravi', 'contact_phone' => '9876543210', 'radius_km' => 12, 'same_day' => true, 'cod' => false],
        ], $admin)->assertStatus(200);

        // what the edit form reads back must carry the choice, or the form re-opens on 'platform'
        $shown = $this->getJson("/api/v1/nurseries/{$uuid}", $admin)->assertStatus(200);
        $this->assertSame('self', $shown->json('data.delivery_mode'));
        $this->assertSame('Ravi', $shown->json('data.self_delivery.contact_name'));
        $this->assertSame(12, (int) $shown->json('data.self_delivery.radius_km'));
    }

    public function test_the_choice_is_mirrored_onto_the_legacy_shop_row_the_fulfilment_engine_reads(): void
    {
        $this->legacyShopsTable();
        $legacyId = DB::table('shops')->insertGetId(['name' => 'Mirror Me', 'delivery_mode' => 'platform', 'created_at' => now(), 'updated_at' => now()]);

        $admin = $this->bearer($this->accessToken('admin@plantathome.test'));
        $uuid = $this->postJson('/api/v1/nurseries', ['name' => 'Mirror Me', 'slug' => 'mirror-me'], $admin)->json('data.uuid');
        DB::table('nursery_nurseries')->where('uuid', $uuid)->update(['legacy_id' => $legacyId]);

        $this->patchJson("/api/v1/nurseries/{$uuid}", [
            'delivery_mode' => 'self',
            'self_delivery' => ['contact_name' => 'Ravi'],
        ], $admin)->assertStatus(200);

        $row = DB::table('shops')->where('id', $legacyId)->first();
        $this->assertSame('self', $row->delivery_mode, 'shops.delivery_mode is what the matching engine reads');
        $this->assertSame('Ravi', json_decode((string) $row->self_delivery, true)['contact_name'] ?? null);
    }

    public function test_an_invalid_mode_is_rejected(): void
    {
        $admin = $this->bearer($this->accessToken('admin@plantathome.test'));
        $uuid = $this->postJson('/api/v1/nurseries', ['name' => 'Bad Mode', 'slug' => 'bad-mode'], $admin)->json('data.uuid');

        $this->patchJson("/api/v1/nurseries/{$uuid}", ['delivery_mode' => 'drone'], $admin)->assertStatus(422);
    }
}
