<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Remove leftover Pickbazar demo variants from real plants (owner go, 2026-10-08).
 *
 * Twenty real plants carried demo `variation_options` beside their real sizes — e.g. Vinca
 * "Picture Book/French ₹140" next to its real "Small ₹299" — listed first on the product page,
 * buyable, and pulling some listings' "from" price down to ₹80. They live only inside
 * `variation_options.options` (the demo attributes have no `attribute_product` rows), so an
 * attribute audit never saw them. Never ordered.
 *
 * A row is removed only when ALL hold: its product is a Plants product; its options name one of
 * the demo axes (DEMO_AXES, case-insensitive); the product keeps at least one real Size-only
 * row; and no order line, review, vendor price or inventory movement references it (those are
 * skipped and reported, never deleted). References that are only copies — server carts,
 * wishlists (their FK cascades), per-variant city availability — are snapshotted and removed
 * with it. Each touched product's min_price/max_price is recomputed from its remaining rows the
 * way ApplySizePricingCommand derives them (min/max of `price`).
 *
 * Everything removed or changed is snapshotted into pah_demo_variant_backup; down() restores it.
 */
return new class extends Migration {
    private const BACKUP = 'pah_demo_variant_backup';
    private const DEMO_AXES = ['aurora pope', 'language', 'book type'];
    /** Tables whose reference means the row is in use: skip it. */
    private const BLOCKING = ['order_product', 'order_items', 'reviews', 'vendor_product_prices', 'inventory_transactions'];
    /** Tables whose reference is a copy: snapshot and delete alongside. */
    private const COPIES = ['carts', 'wishlists', 'product_city_availability'];

    public function up(): void
    {
        if (!Schema::hasTable('variation_options') || !Schema::hasTable('products') || !Schema::hasTable('types')) {
            return;
        }
        $typeId = DB::table('types')->where('slug', 'plants')->orderBy('id')->value('id');
        if (!$typeId) {
            return;
        }
        if (!Schema::hasTable(self::BACKUP)) {
            Schema::create(self::BACKUP, function (Blueprint $t) {
                $t->id();
                $t->string('source_table', 64);
                $t->unsignedBigInteger('source_id')->nullable();
                $t->longText('row_json');
                $t->timestamp('created_at')->nullable();
            });
        }

        try {
            DB::transaction(function () use ($typeId) {
                $now = now();
                $rows = DB::table('variation_options')
                    ->join('products', 'products.id', '=', 'variation_options.product_id')
                    ->where('products.type_id', $typeId)
                    ->select('variation_options.*')
                    ->get();

                $byProduct = $rows->groupBy('product_id');
                $junk = [];
                foreach ($byProduct as $productId => $productRows) {
                    $isDemo = fn ($r) => collect($this->axes($r->options))->contains(fn ($n) => in_array($n, self::DEMO_AXES, true));
                    $isSizeOnly = fn ($r) => ($axes = $this->axes($r->options)) && $axes === ['size'];
                    if (!$productRows->contains($isSizeOnly)) {
                        continue; // never strip a product of its only variants
                    }
                    foreach ($productRows->filter($isDemo) as $r) {
                        $junk[$r->id] = $r;
                    }
                }
                if (!$junk) {
                    return;
                }

                // In use anywhere that matters → skip and report.
                $blocked = [];
                foreach (self::BLOCKING as $table) {
                    if (Schema::hasTable($table) && Schema::hasColumn($table, 'variation_option_id')) {
                        foreach (DB::table($table)->whereIn('variation_option_id', array_keys($junk))->pluck('variation_option_id') as $id) {
                            $blocked[$id] = $table;
                        }
                    }
                }
                foreach ($blocked as $id => $table) {
                    Log::warning("remove_demo_variation_options: kept variation_option {$id} (referenced by {$table})");
                    echo "SKIP variation_option {$id}: referenced by {$table}\n";
                    unset($junk[$id]);
                }
                if (!$junk) {
                    return;
                }
                $ids = array_keys($junk);

                foreach (self::COPIES as $table) {
                    if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'variation_option_id')) {
                        continue;
                    }
                    foreach (DB::table($table)->whereIn('variation_option_id', $ids)->get() as $copy) {
                        $this->snapshot($table, $copy->id ?? null, $copy, $now);
                    }
                    DB::table($table)->whereIn('variation_option_id', $ids)->delete();
                }

                $productIds = collect($junk)->pluck('product_id')->unique()->values()->all();
                foreach (DB::table('products')->whereIn('id', $productIds)->get(['id', 'min_price', 'max_price']) as $p) {
                    $this->snapshot('products', $p->id, $p, $now);
                }
                foreach ($junk as $row) {
                    $this->snapshot('variation_options', $row->id, $row, $now);
                }
                DB::table('variation_options')->whereIn('id', $ids)->delete();

                foreach ($productIds as $pid) {
                    $prices = DB::table('variation_options')->where('product_id', $pid)->pluck('price')->map(fn ($v) => (float) $v)->filter(fn ($v) => $v > 0);
                    if ($prices->isNotEmpty()) {
                        DB::table('products')->where('id', $pid)->update(['min_price' => $prices->min(), 'max_price' => $prices->max(), 'updated_at' => $now]);
                    }
                }
                echo 'Removed ' . count($ids) . ' demo variation_options from ' . count($productIds) . " plants.\n";
            });
        } catch (\Throwable $e) {
            Log::error('remove_demo_variation_options failed: ' . $e->getMessage());
            echo "WARN remove_demo_variation_options failed: {$e->getMessage()}\n";
        }

        $this->bustCaches();
    }

    /** Lower-cased attribute names a variation_options.options JSON carries. */
    private function axes($options): array
    {
        $list = json_decode((string) $options, true);
        if (!is_array($list)) {
            return [];
        }
        return collect($list)->map(fn ($o) => strtolower(trim((string) ($o['name'] ?? ''))))->filter()->values()->all();
    }

    private function snapshot(string $table, $id, object $row, $now): void
    {
        DB::table(self::BACKUP)->insert([
            'source_table' => $table,
            'source_id'    => $id,
            'row_json'     => json_encode($row),
            'created_at'   => $now,
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::BACKUP)) {
            return;
        }
        DB::transaction(function () {
            // Parents first (variation_options), then the copies that referenced them.
            $order = ['variation_options' => 0, 'carts' => 1, 'wishlists' => 1, 'product_city_availability' => 1, 'products' => 2];
            $rows = DB::table(self::BACKUP)->get()->sortBy(fn ($b) => $order[$b->source_table] ?? 3);
            foreach ($rows as $b) {
                $data = json_decode($b->row_json, true);
                if ($b->source_table === 'products') {
                    DB::table('products')->where('id', $b->source_id)->update(['min_price' => $data['min_price'], 'max_price' => $data['max_price']]);
                } elseif (Schema::hasTable($b->source_table)) {
                    DB::table($b->source_table)->insertOrIgnore($data);
                }
            }
        });
        Schema::drop(self::BACKUP);
        $this->bustCaches();
    }

    /** Product lists, facets and PDPs are cached under the products version key. */
    private function bustCaches(): void
    {
        try {
            Cache::forever('products:ver', (int) Cache::get('products:ver', 1) + 1);
        } catch (\Throwable $e) {
            // the 300 s caches expire on their own
        }
    }
};
