<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seed the Borzo (instant / same-city) go-live blockers into the admin Setup → Pending Tasks
 * checklist so they're tracked in-product. Idempotent (updateOrInsert keyed on title+category).
 * The verified facts behind these: the test token authenticates + non-COD quotes succeed against
 * the 1.8 API, but COD is gated by a Borzo-side cash-voucher agreement (Borzo returned
 * "cod_agreement_required"), so COD needs that agreement enabled before it can go live.
 */
return new class extends Migration {
    private array $tasks = [
        ['Set Borzo env vars on the API', 'Add "borzo" to COURIER_PROVIDER (e.g. COURIER_PROVIDER=shiprocket,borzo) and set BORZO_TOKEN + BORZO_BASE_URL (test: https://robotapitest-in.borzodelivery.com/api/business/1.8, prod: https://robot-in.borzodelivery.com/api/business/1.8). Keep settings.options.courier.enabled ON. The Borzo lane is inert until this is set.', 'setup', 'high'],
        ['Enable Borzo COD (cash-voucher) agreement', 'COD orders return "cod_agreement_required" until the cash-on-delivery / cash-voucher agreement is enabled on the Borzo business account. Until then, same-city orders can ship prepaid via Borzo but COD will be rejected — sign/enable the COD agreement with Borzo to unlock COD.', 'setup', 'high'],
        ['Register Borzo callback (webhook) URL', 'In the Borzo cabinet → Integration tab, set the Callback URL to <API_HOST>/api/webhooks/borzo and set the callback token; also set BORZO_CALLBACK_TOKEN on the API. Status auto-advances orders (and fires vendor settlement on delivery). NB: the handler re-fetches authoritative status from Borzo, so a missing/invalid signature can never be spoofed.', 'infra', 'high'],
        ['Set vendor + customer coordinates for Borzo', 'Borzo geocodes from the address, but is far more reliable with lat/lng. Ensure vendor shop addresses and checkout addresses carry coordinates (the checkout map picker already captures them) so instant pickups/drops resolve to the right point.', 'setup', 'medium'],
        ['Rotate the Borzo + Shiprocket API tokens', 'The Borzo token and Shiprocket credentials shared during integration must be rotated before/at go-live (treat any pasted secret as exposed). Set the fresh secrets only as API env vars — never commit them.', 'infra', 'high'],
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
