<?php

namespace App\Modules\Nursery\Application\Subscribers;

use App\Shared\Events\IntegrationMessage;
use Illuminate\Support\Facades\DB;

/**
 * Projects v2 nursery changes back onto the legacy `shops`/`balances` rows so
 * the storefront and legacy admin keep seeing current vendor data during the
 * migration window. Only fires for nurseries that carry a legacy_id. No
 * try/catch — a failure surfaces to the relay, which retries.
 */
final class ProjectNurseryToLegacyShop
{
    public function handle(IntegrationMessage $message): void
    {
        $payload = $message->payload;
        $legacyId = $payload['legacy_id'] ?? null;

        if (! $legacyId) {
            return; // v2-native nursery — nothing to mirror.
        }

        if (in_array($message->eventName, ['nursery.approved', 'nursery.updated'], true)) {
            DB::table('shops')->where('id', $legacyId)->update([
                'name'        => $payload['name'] ?? null,
                'slug'        => $payload['slug'] ?? null,
                'description' => $payload['description'] ?? null,
                'is_active'   => ($payload['status'] ?? null) === 'active',
            ]);
        }

        if (array_key_exists('commission_rate', $payload) && $payload['commission_rate'] !== null) {
            DB::table('balances')->where('shop_id', $legacyId)->update([
                'admin_commission_rate' => $payload['commission_rate'],
            ]);
        }
    }
}
