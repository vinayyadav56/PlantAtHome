<?php

namespace App\Modules\Nursery\Application\Subscribers;

use App\Modules\Nursery\Infrastructure\Models\Nursery;
use App\Shared\Events\IntegrationMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
            $update = [
                'name'        => $payload['name'] ?? null,
                'slug'        => $payload['slug'] ?? null,
                'description' => $payload['description'] ?? null,
                'is_active'   => ($payload['status'] ?? null) === 'active',
            ];

            // Vendor-profile mirror: read the current nursery row (projection =
            // last-write-wins) and sync the extended columns the legacy admin/
            // storefront read. hasColumn-guarded — staging/prod schema drift.
            $nursery = Nursery::query()->where('uuid', $payload['nursery_uuid'] ?? null)->first();
            if ($nursery) {
                foreach (['contact_person', 'mobile', 'upi', 'lat', 'lng', 'gst_number'] as $column) {
                    if (Schema::hasColumn('shops', $column)) {
                        $update[$column] = $nursery->{$column};
                    }
                }
                $update['settings'] = $nursery->settings !== null ? json_encode($nursery->settings) : null;
            }

            DB::table('shops')->where('id', $legacyId)->update($update);

            if ($nursery && $nursery->balance && $nursery->balance->payment_info !== null) {
                DB::table('balances')->where('shop_id', $legacyId)->update([
                    'payment_info' => json_encode($nursery->balance->payment_info),
                ]);
            }
        }

        if (array_key_exists('commission_rate', $payload) && $payload['commission_rate'] !== null) {
            DB::table('balances')->where('shop_id', $legacyId)->update([
                'admin_commission_rate' => $payload['commission_rate'],
            ]);
        }
    }
}
