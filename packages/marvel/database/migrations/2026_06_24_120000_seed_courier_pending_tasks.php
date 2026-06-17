<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seed the Shiprocket courier go-live blockers (+ a Porter evaluation item) into the admin
 * Setup → Pending Tasks checklist so they're tracked in-product. Idempotent (updateOrInsert
 * keyed on title+category) — re-running won't duplicate, and since migrations run once it
 * won't resurrect tasks an admin later ticks off or deletes.
 */
return new class extends Migration {
    private array $tasks = [
        ['Create Shiprocket account + set courier env', 'Sign up for Shiprocket, then set COURIER_PROVIDER=shiprocket + SHIPROCKET_EMAIL/PASSWORD/BASE_URL/SHIPROCKET_WEBHOOK_TOKEN on the API, and enable settings.options.courier.enabled. Courier is inert until this is done.', 'setup', 'high'],
        ['Register each vendor pickup in Shiprocket', 'For every active vendor, register their pickup address as a Shiprocket pickup location: order page → Courier panel → "Register pickup" (or POST shops/{id}/sync-pickup). Required before any courier shipment can be booked.', 'setup', 'high'],
        ['Add product weights + dimensions for courier', 'Set product weight (grams) + L/B/H (cm) so courier rates + AWBs are accurate. Until set, the configurable default package (settings.options.courier.default_package) is used.', 'setup', 'medium'],
        ['Register Shiprocket status webhook', 'In the Shiprocket dashboard add the webhook URL <API_HOST>/api/webhooks/shiprocket with header x-api-key = SHIPROCKET_WEBHOOK_TOKEN, so delivery status auto-advances orders (and fires vendor settlement on delivery).', 'infra', 'high'],
        ['Set up Shiprocket COD remittance', 'Configure the COD remittance bank account with Shiprocket and obtain access to their COD remittance feed, for the COD reconciliation report (reports/cod-reconciliation).', 'setup', 'medium'],
        ['Evaluate / integrate Porter for hyperlocal delivery', 'Porter (porter.in) for the same-city/local delivery lane as a 2nd courier provider via CourierProviderInterface (mirrors ShiprocketClient). Needs Porter-for-Business credentials + Porter\'s official partner API docs (gated, not fully public) to build + go live.', 'feature', 'medium'],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('admin_tasks')) {
            return;
        }
        $now = now();
        foreach ($this->tasks as [$title, $description, $category, $priority]) {
            DB::table('admin_tasks')->updateOrInsert(
                ['title' => $title, 'category' => $category],
                ['description' => $description, 'priority' => $priority, 'status' => 'pending', 'updated_at' => $now, 'created_at' => $now],
            );
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('admin_tasks')) {
            return;
        }
        DB::table('admin_tasks')->whereIn('title', array_map(fn ($t) => $t[0], $this->tasks))->delete();
    }
};
