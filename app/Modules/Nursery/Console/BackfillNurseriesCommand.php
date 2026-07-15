<?php

namespace App\Modules\Nursery\Console;

use App\Modules\Nursery\Domain\NurseryStatus;
use App\Shared\Infrastructure\Backfill\BackfillCommand;
use App\Shared\Infrastructure\Backfill\LegacyUuid;
use Illuminate\Support\Facades\DB;

/**
 * v2:backfill-nurseries — legacy `shops` (vendors) + `balances` + `withdraws`
 * into nursery_*. Status mapping: shops.is_active → active, else pending.
 * Withdrawal statuses copy verbatim (same strings). Run AFTER
 * v2:backfill-users so the identity_users nursery-scope fix-up finds its rows.
 */
class BackfillNurseriesCommand extends BackfillCommand
{
    protected $signature = 'v2:backfill-nurseries {--dry-run} {--verify} {--since=} {--since-cursor}';

    protected $description = 'Backfill legacy shops/balances/withdraws into nursery_* (idempotent upsert by legacy_id)';

    /** @var array<int,float|null>|null legacy shop id -> admin_commission_rate */
    private ?array $commissionByShop = null;

    /** @var array<int|string,int|null> legacy shop id -> nursery_nurseries.id */
    private array $nurseryIdByShop = [];

    protected function steps(): array
    {
        return [
            [
                'resource' => 'nurseries',
                'source' => 'shops',
                'target' => 'nursery_nurseries',
                'map' => function (object $row): ?array {
                    return [
                        'uuid' => LegacyUuid::for('shops', $row->id),
                        'legacy_id' => $row->id,
                        'owner_user_uuid' => $row->owner_id ? LegacyUuid::for('users', $row->owner_id) : null,
                        'name' => $row->name ?? 'shop-'.$row->id,
                        'slug' => $row->slug,
                        'description' => $row->description,
                        'logo' => $row->logo,
                        'cover_image' => $row->cover_image,
                        'address' => $row->address,
                        'settings' => $row->settings,
                        'status' => $row->is_active ? NurseryStatus::ACTIVE : NurseryStatus::PENDING,
                        'commission_rate' => $this->commissionRates()[$row->id] ?? null,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ];
                },
            ],
            [
                'resource' => 'nursery_balances',
                'source' => 'balances',
                'target' => 'nursery_balances',
                'map' => function (object $row): ?array {
                    $nurseryId = $this->nurseryIdForShop($row->shop_id);
                    if ($nurseryId === null) {
                        return null; // orphan balance — its shop was never backfilled
                    }

                    return [
                        'legacy_id' => $row->id,
                        'nursery_id' => $nurseryId,
                        'total_earnings' => (float) ($row->total_earnings ?? 0),
                        'withdrawn_amount' => (float) ($row->withdrawn_amount ?? 0),
                        'current_balance' => (float) ($row->current_balance ?? 0),
                        'payment_info' => $row->payment_info,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ];
                },
            ],
            [
                'resource' => 'nursery_withdrawals',
                'source' => 'withdraws',
                'target' => 'nursery_withdrawals',
                'map' => function (object $row): ?array {
                    $nurseryId = $this->nurseryIdForShop($row->shop_id);
                    if ($nurseryId === null) {
                        return null;
                    }

                    return [
                        'uuid' => LegacyUuid::for('withdraws', $row->id),
                        'legacy_id' => $row->id,
                        'nursery_id' => $nurseryId,
                        'amount' => (float) $row->amount,
                        'status' => $row->status,             // EXACT legacy strings
                        'payment_method' => $row->payment_method,
                        'details' => $row->details,
                        'note' => $row->note,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ];
                },
            ],
        ];
    }

    public function handle(): int
    {
        $result = parent::handle();

        if ($result === self::SUCCESS && ! $this->option('dry-run') && ! $this->option('verify')) {
            $this->fixUpIdentityNurseryScope();
        }

        return $result;
    }

    /**
     * Point backfilled identity users at their nursery scope: legacy
     * users.shop_id → LegacyUuid(shops:<id>) on identity_users.nursery_id.
     */
    private function fixUpIdentityNurseryScope(): void
    {
        $updated = 0;

        DB::table('users')
            ->whereNotNull('shop_id')
            ->select('id', 'shop_id')
            ->orderBy('id')
            ->each(function (object $row) use (&$updated) {
                $updated += DB::table('identity_users')
                    ->where('legacy_id', $row->id)
                    ->update(['nursery_id' => LegacyUuid::for('shops', $row->shop_id)]);
            });

        $this->info(sprintf('  identity_users.nursery_id fix-up: %d rows', $updated));
    }

    /** @return array<int,float|null> preloaded balances keyed by shop_id */
    private function commissionRates(): array
    {
        if ($this->commissionByShop === null) {
            $this->commissionByShop = DB::table('balances')
                ->pluck('admin_commission_rate', 'shop_id')
                ->all();
        }

        return $this->commissionByShop;
    }

    /** Lazily resolve (and cache) a nursery id from a legacy shop id — step (a) runs first. */
    private function nurseryIdForShop(int|string $shopId): ?int
    {
        if (! array_key_exists($shopId, $this->nurseryIdByShop)) {
            $id = DB::table('nursery_nurseries')->where('legacy_id', $shopId)->value('id');
            $this->nurseryIdByShop[$shopId] = $id !== null ? (int) $id : null;
        }

        return $this->nurseryIdByShop[$shopId];
    }
}
