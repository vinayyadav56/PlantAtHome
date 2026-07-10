<?php

namespace Tests\Feature\Marketing;

use App\Modules\Identity\Database\Seeders\IdentityAccessSeeder;
use App\Shared\Events\OutboxRelay;
use Illuminate\Support\Str;

/**
 * Phase 10 acceptance: coupons feed Pricing; order events drive Notifications +
 * Analytics off the request path (via the outbox); CMS pages/banners are city-
 * targetable.
 */
class MarketingTest extends MarketingTestCase
{
    private const NA = IdentityAccessSeeder::NURSERY_A;

    private function admin(): array
    {
        return $this->bearer($this->accessToken('admin@plantathome.test'));
    }

    private function customer(): array
    {
        return $this->bearer($this->accessToken('customer@plantathome.test'));
    }

    /* ── Promotions: coupon feeds Pricing ──────────────────────────────────── */

    public function test_a_coupon_discounts_a_price_quote(): void
    {
        $variant = (string) Str::uuid();
        $this->postJson('/api/v1/pricing/base-prices', ['sellable_type' => 'variant', 'sellable_uuid' => $variant, 'amount' => 200], $this->admin())->assertStatus(201);
        $this->postJson('/api/v1/promotions/coupons', ['code' => 'SAVE10', 'type' => 'percentage', 'value' => 10], $this->admin())->assertStatus(201);

        // Without coupon → 200.00; with SAVE10 → 20.00 off.
        $this->postJson('/api/v1/pricing/quote', ['variant_uuid' => $variant, 'nursery_id' => self::NA])
            ->assertJsonPath('data.total.amount_minor', 20000);

        $res = $this->postJson('/api/v1/pricing/quote', ['variant_uuid' => $variant, 'nursery_id' => self::NA, 'coupon' => 'SAVE10']);
        $this->assertSame(2000, $res->json('data.discount_total.amount_minor'));
        $this->assertSame(18000, $res->json('data.taxable.amount_minor'));
        $codes = array_column($res->json('data.discounts'), 'type');
        $this->assertContains('coupon', $codes);
    }

    public function test_coupon_validate_endpoint_and_min_subtotal(): void
    {
        $this->postJson('/api/v1/promotions/coupons', ['code' => 'BIG50', 'type' => 'fixed', 'value' => 50, 'min_subtotal' => 100], $this->admin())->assertStatus(201);

        $this->postJson('/api/v1/promotions/validate', ['code' => 'BIG50', 'subtotal_minor' => 20000])
            ->assertJsonPath('data.valid', true)->assertJsonPath('data.discount_minor', 5000);
        // Below min subtotal → rejected.
        $this->postJson('/api/v1/promotions/validate', ['code' => 'BIG50', 'subtotal_minor' => 5000])
            ->assertJsonPath('data.valid', false)->assertJsonPath('data.reason', 'MIN_SUBTOTAL_NOT_MET');
        // Unknown code.
        $this->postJson('/api/v1/promotions/validate', ['code' => 'NOPE', 'subtotal_minor' => 20000])
            ->assertJsonPath('data.valid', false)->assertJsonPath('data.reason', 'UNKNOWN_COUPON');
    }

    /* ── Notifications + Analytics: driven by the order event ───────────────── */

    public function test_placing_an_order_drives_notifications_and_analytics(): void
    {
        $order = $this->placeOrder(); // emits sales.order_placed to the outbox
        $this->app->make(OutboxRelay::class)->relay(); // deliver off the request path

        // Notifications: an email + a whatsapp log for the order.
        $log = $this->getJson('/api/v1/notifications/log?event=sales.order_placed', $this->admin())->assertStatus(200);
        $channels = array_column($log->json('data'), 'channel');
        $this->assertContains('email', $channels);
        $this->assertContains('whatsapp', $channels);

        // Analytics: KPIs reflect the order.
        $kpis = $this->getJson('/api/v1/analytics/kpis', $this->admin())->assertStatus(200);
        $this->assertSame(1, $kpis->json('data.orders'));
        $this->assertGreaterThan(0, $kpis->json('data.revenue'));
        $this->assertGreaterThan(0, $kpis->json('data.aov'));
        $this->assertNotEmpty($kpis->json('data.per_vendor'));
    }

    private function placeOrder(): string
    {
        $admin = $this->admin();
        $p = $this->postJson('/api/v1/catalog/products', ['name' => 'Order Palm', 'status' => 'published', 'variants' => [['size_code' => 'M']]], $admin);
        $variant = $p->json('data.variants.0.uuid');
        $this->postJson('/api/v1/pricing/base-prices', ['sellable_type' => 'variant', 'sellable_uuid' => $variant, 'amount' => 150], $admin)->assertStatus(201);
        $this->putJson('/api/v1/inventory/stock', ['sellable_type' => 'variant', 'sellable_uuid' => $variant, 'nursery_id' => self::NA, 'qty_on_hand' => 5], $admin)->assertStatus(200);

        $this->postJson('/api/v1/cart/items', ['variant_uuid' => $variant, 'nursery_id' => self::NA, 'qty' => 1], $this->customer())->assertStatus(201);
        $checkout = $this->postJson('/api/v1/checkout', [], $this->customer())->json('data.checkout_uuid');

        return $this->postJson("/api/v1/checkout/{$checkout}/pay", ['success' => true], $this->customer())->json('data.order.uuid');
    }

    /* ── CMS: city-targetable ──────────────────────────────────────────────── */

    public function test_cms_pages_are_city_targetable(): void
    {
        $admin = $this->admin();
        $city = (string) Str::uuid();
        $this->postJson('/api/v1/cms/pages', ['slug' => 'about', 'title' => 'About (global)', 'body' => 'global'], $admin)->assertStatus(201);
        $this->postJson('/api/v1/cms/pages', ['slug' => 'about', 'title' => 'About (city)', 'body' => 'city', 'city_uuid' => $city], $admin)->assertStatus(201);

        $this->getJson('/api/v1/cms/pages/about')->assertStatus(200)->assertJsonPath('data.title', 'About (global)');
        $this->getJson("/api/v1/cms/pages/about?city={$city}")->assertStatus(200)->assertJsonPath('data.title', 'About (city)');
    }

    public function test_cms_banners_return_city_plus_global(): void
    {
        $admin = $this->admin();
        $city = (string) Str::uuid();
        $this->postJson('/api/v1/cms/banners', ['image_url' => 'g.jpg', 'position' => 'home'], $admin)->assertStatus(201);
        $this->postJson('/api/v1/cms/banners', ['image_url' => 'c.jpg', 'position' => 'home', 'city_uuid' => $city], $admin)->assertStatus(201);

        $global = $this->getJson('/api/v1/cms/banners?position=home')->json('data');
        $this->assertCount(1, $global); // only global for no-city

        $withCity = $this->getJson("/api/v1/cms/banners?position=home&city={$city}")->json('data');
        $this->assertCount(2, $withCity); // city + global
    }

    public function test_cms_and_promo_writes_require_permission(): void
    {
        $customer = $this->customer();
        $this->postJson('/api/v1/cms/pages', ['slug' => 'x', 'title' => 'x'], $customer)->assertStatus(403);
        $this->postJson('/api/v1/promotions/coupons', ['code' => 'X', 'type' => 'fixed', 'value' => 1], $customer)->assertStatus(403);
        $this->getJson('/api/v1/analytics/kpis', $customer)->assertStatus(403);
    }
}
