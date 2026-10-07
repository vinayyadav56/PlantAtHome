<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * List the seeded gardening tools (owner, 2026-10-07: "make the 40 tools live").
 *
 * PlantAtHomeToolsSeeder created the 40 tools in packages/marvel/data/tools.json but never
 * granted Master Catalog membership: `is_available_product` / `listing_enabled` default to
 * false and nothing backfills them, so every tools list returned 0 and /tools sold nothing.
 * This switches both flags on for those slugs only — published, live (not soft-deleted)
 * Tools rows — and stamps `available_at` where it is empty. A tool an admin drafted stays a
 * draft; no other product is touched. (The seeder now creates new tools listable itself.)
 *
 * Every changed row is snapshotted into pah_tools_listing_backup first; down() restores it.
 */
return new class extends Migration {
    private const BACKUP = 'pah_tools_listing_backup';

    public function up(): void
    {
        if (!Schema::hasTable('products') || !Schema::hasTable('types')
            || !Schema::hasColumns('products', ['is_available_product', 'listing_enabled', 'available_at', 'status'])) {
            return;
        }
        $typeId = DB::table('types')->where('slug', 'tools')->orderBy('id')->value('id');
        $path = base_path('packages/marvel/data/tools.json');
        if (!$typeId || !is_file($path)) {
            return;
        }
        $slugs = collect(json_decode((string) file_get_contents($path), true) ?: [])
            ->map(fn ($t) => trim((string) ($t['slug'] ?? Str::slug((string) ($t['name'] ?? '')))))
            ->filter()->unique()->values()->all();
        if (!$slugs) {
            return;
        }

        // DDL commits implicitly in MySQL, so the backup table exists before the transaction.
        if (!Schema::hasTable(self::BACKUP)) {
            Schema::create(self::BACKUP, function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('product_id');
                $t->boolean('old_is_available_product')->nullable();
                $t->boolean('old_listing_enabled')->nullable();
                $t->timestamp('old_available_at')->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }

        try {
            DB::transaction(function () use ($typeId, $slugs) {
                $now = now();
                $rows = DB::table('products')
                    ->where('type_id', $typeId)->where('language', 'en')->whereIn('slug', $slugs)
                    ->where('status', 'publish')->whereNull('deleted_at')
                    ->where(fn ($q) => $q->where('is_available_product', false)->orWhere('listing_enabled', false))
                    ->get(['id', 'is_available_product', 'listing_enabled', 'available_at']);

                foreach ($rows as $r) {
                    DB::table(self::BACKUP)->insert([
                        'product_id'               => $r->id,
                        'old_is_available_product' => $r->is_available_product,
                        'old_listing_enabled'      => $r->listing_enabled,
                        'old_available_at'         => $r->available_at,
                        'created_at'               => $now,
                    ]);
                    DB::table('products')->where('id', $r->id)->update([
                        'is_available_product' => true,
                        'listing_enabled'      => true,
                        'available_at'         => $r->available_at ?? $now,
                    ]);
                }
            });
        } catch (\Throwable $e) {
            // Never a reason to fail a deploy; the transaction left nothing half-done.
            // Loud in the migrate output and the log, then verified via the API.
            Log::error('list_seeded_tools failed: ' . $e->getMessage());
            echo "WARN list_seeded_tools failed: {$e->getMessage()}\n";
        }

        $this->bustProductsCache();
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::BACKUP)) {
            return;
        }
        DB::transaction(function () {
            foreach (DB::table(self::BACKUP)->orderByDesc('id')->get() as $b) {
                DB::table('products')->where('id', $b->product_id)->update([
                    'is_available_product' => $b->old_is_available_product,
                    'listing_enabled'      => $b->old_listing_enabled,
                    'available_at'         => $b->old_available_at,
                ]);
            }
        });
        Schema::drop(self::BACKUP);
        $this->bustProductsCache();
    }

    /** Same key ApiResponseCache::bustResponseCache('products') bumps (lists, facets, PDP). */
    private function bustProductsCache(): void
    {
        try {
            Cache::forever('products:ver', (int) Cache::get('products:ver', 1) + 1);
        } catch (\Throwable $e) {
            // the 300 s caches expire on their own
        }
    }
};
