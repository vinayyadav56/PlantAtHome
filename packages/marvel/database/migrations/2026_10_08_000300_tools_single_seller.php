<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Tools = one seller, nationwide (owner, 2026-10-08): sold by PlantAtHome itself at its rate +
 * the Tools margin, the same price and stock in every city, never city-gated.
 *
 * Merges `seller_model: single_vendor, seller_shop_id: <PlantAtHome>` into the `tools` row of
 * global_vertical_settings (created active when missing; every other setting survives), then
 * re-mirrors every tool through AvailabilityService::recomputeForProduct: a tool the seller has
 * a rate for gets rate + margin, one without goes out of stock with its price untouched. No
 * rates are invented — the owner enters them on the PlantAtHome store.
 *
 * The shop is looked up by slug, never Shop::masterId() (which CREATES the master shop): no
 * `plantathome` shop ⇒ log and do nothing.
 *
 * The tools row and every tool's price/stock columns are snapshotted into
 * pah_tools_seller_backup first; down() restores them.
 */
return new class extends Migration {
    private const BACKUP = 'pah_tools_seller_backup';
    private const PRODUCT_COLS = ['price', 'sale_price', 'min_price', 'max_price', 'in_stock'];

    public function up(): void
    {
        if (!Schema::hasTable('global_vertical_settings') || !Schema::hasTable('shops') || !Schema::hasTable('types')) {
            return;
        }
        $typeIds = DB::table('types')->where('slug', 'tools')->pluck('id')->all();
        if (!$typeIds) {
            return;
        }
        // The same rule the admin endpoint enforces: an active seller that is not on hold.
        $shop = DB::table('shops')->where('slug', 'plantathome')->first();
        $shopId = ($shop && $shop->is_active && ($shop->approval_status ?? null) !== 'on_hold') ? $shop->id : null;
        if (!$shopId) {
            Log::warning('tools_single_seller: no active, unheld shop with slug plantathome — Tools left multi-vendor');
            echo "SKIP tools_single_seller: no active, unheld shop with slug plantathome\n";
            return;
        }

        // DDL commits implicitly in MySQL, so the backup table exists before the transaction.
        if (!Schema::hasTable(self::BACKUP)) {
            Schema::create(self::BACKUP, function (Blueprint $t) {
                $t->id();
                $t->string('source_table', 64);
                $t->unsignedBigInteger('source_id')->nullable();
                $t->longText('row_json')->nullable(); // null for the tools row = it did not exist
                $t->timestamp('created_at')->nullable();
            });
        }

        $productIds = [];
        try {
            DB::transaction(function () use ($typeIds, $shopId, &$productIds) {
                $now = now();
                $row = DB::table('global_vertical_settings')->where('vertical_slug', 'tools')->first();
                $this->snapshot('global_vertical_settings', $row->id ?? null, $row, $now);

                $settings = array_merge(
                    (array) (json_decode((string) ($row->settings ?? ''), true) ?: []),
                    ['seller_model' => 'single_vendor', 'seller_shop_id' => (int) $shopId],
                );
                if ($row) {
                    DB::table('global_vertical_settings')->where('id', $row->id)
                        ->update(['settings' => json_encode($settings), 'updated_at' => $now]);
                } else {
                    DB::table('global_vertical_settings')->insert([
                        'vertical_slug' => 'tools', 'is_active' => true, 'status' => 'active',
                        'settings' => json_encode($settings), 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }

                $tools = DB::table('products')->whereIn('type_id', $typeIds)
                    ->where(fn ($q) => $q->whereNull('product_type')->orWhere('product_type', '!=', 'bundle'))
                    ->when(Schema::hasColumn('products', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                    ->get(array_merge(['id'], self::PRODUCT_COLS));
                foreach ($tools as $p) {
                    $this->snapshot('products', $p->id, $p, $now);
                    $productIds[] = (int) $p->id;
                }
            });
        } catch (\Throwable $e) {
            Log::error('tools_single_seller failed: ' . $e->getMessage());
            echo "WARN tools_single_seller failed: {$e->getMessage()}\n";
            return;
        }

        $this->bustCaches();
        $this->recompute($productIds);
        echo 'Tools sold by shop ' . $shopId . '; re-mirrored ' . count($productIds) . " tools.\n";
    }

    private function snapshot(string $table, $id, ?object $row, $now): void
    {
        DB::table(self::BACKUP)->insert([
            'source_table' => $table,
            'source_id'    => $id,
            'row_json'     => $row ? json_encode($row) : null,
            'created_at'   => $now,
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::BACKUP)) {
            return;
        }
        $productIds = [];
        DB::transaction(function () use (&$productIds) {
            foreach (DB::table(self::BACKUP)->orderBy('id')->get() as $b) {
                $data = json_decode((string) $b->row_json, true);
                if ($b->source_table === 'products') {
                    DB::table('products')->where('id', $b->source_id)
                        ->update(array_intersect_key((array) $data, array_flip(self::PRODUCT_COLS)));
                    $productIds[] = (int) $b->source_id;
                } elseif ($data === null) {
                    DB::table('global_vertical_settings')->where('vertical_slug', 'tools')->delete();
                } else {
                    DB::table('global_vertical_settings')->where('id', $b->source_id)
                        ->update(['settings' => $data['settings'], 'updated_at' => $data['updated_at']]);
                }
            }
        });
        Schema::drop(self::BACKUP);
        $this->bustCaches();
        // Back to multi-vendor: rebuild their city projection (prices stay as restored above).
        $this->recompute($productIds);
    }

    /** Re-mirror (single seller) or re-project (multi-vendor) each tool. Never fails the deploy. */
    private function recompute(array $productIds): void
    {
        try {
            \Marvel\Services\MarginResolver::flush();
            $svc = new \Marvel\Services\AvailabilityService();
            foreach ($productIds as $pid) {
                $svc->recomputeForProduct($pid);
            }
        } catch (\Throwable $e) {
            Log::error('tools_single_seller recompute failed: ' . $e->getMessage());
            echo "WARN tools_single_seller recompute failed ({$e->getMessage()}) — run marvel:recompute-city-availability\n";
        }
    }

    /** The availability map (seller model) and the product lists / PDPs. */
    private function bustCaches(): void
    {
        try {
            // Through the service, so its per-request version memo moves with the bump.
            app(\Marvel\Services\ServiceAvailabilityService::class)->bust();
            Cache::forever('products:ver', (int) Cache::get('products:ver', 1) + 1);
        } catch (\Throwable $e) {
            // the 300 s caches expire on their own
        }
    }
};
