<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's category photos (2026-10-07) on the Plants categories, and those categories
 * switched on and shown as tiles even while they hold no plant.
 *
 * The photos live ONCE on the shared media bucket under category-tiles/2026-10-07/
 * (1200 px original, 600 px thumbnail): staging and production share that bucket, and the
 * prefix is outside every media GC / purge manifest. Each category gets its photo,
 * is_active = 1, show_on_homepage = 1 and the design mock's tile order. A category an
 * environment lacks (staging has no Bonsai / Palms / Rare & Exotic) is created with
 * production's name and copy. The empty name-duplicates production flagged
 * (indoor-plants, outdoor-plants, flowering-plants) are unflagged so "Indoor Plants" never
 * shows twice, and any other flagged Plants category moves behind the owner's tiles.
 * A "Retired — …" description (Medicinal's) is cleared: it would render on the live page.
 *
 * Every touched row is snapshotted into pah_category_tiles_backup first; down() restores
 * it. A migration runs once per environment, so later admin edits are never overwritten.
 * The image id is a string on purpose: the admin uploader only renders tiles with an id,
 * and nothing dereferences a category image id.
 */
return new class extends Migration {
    private const BASE = 'https://cdn.plantathome.in/category-tiles/2026-10-07/';
    private const BACKUP = 'pah_category_tiles_backup';

    /** slug => [tile order, name + details used only when the category has to be created] */
    private const TILES = [
        'indoor'           => [1, 'Indoor Plants', 'Plants that thrive inside the home or office.'],
        'outdoor'          => [2, 'Outdoor Plants', null],
        'flowering'        => [3, 'Flowering Plants', null],
        'succulents-cacti' => [4, 'Succulents & Cacti', null],
        'foliage'          => [5, 'Foliage Plants', null],
        'herbs'            => [6, 'Herbs', null],
        'climbers-vines'   => [7, 'Climbers & Creepers', null],
        'palms-tropical'   => [8, 'Palms & Tropical Plants', 'Palms and tropical foliage.'],
        'bonsai'           => [9, 'Bonsai', 'Miniature trained trees.'],
        'rare-exotic'      => [10, 'Rare & Exotic Plants', 'Collector and hard-to-find plants.'],
        'medicinal'        => [11, 'Medicinal', null],
    ];

    /** Empty duplicates => the category that now carries the photo. */
    private const DUPLICATES = [
        'indoor-plants'    => 'indoor',
        'outdoor-plants'   => 'outdoor',
        'flowering-plants' => 'flowering',
    ];

    /** Where any other flagged Plants category goes: behind the owner's tiles. */
    private const AFTER_TILES = 100;

    public function up(): void
    {
        if (!Schema::hasTable('categories') || !Schema::hasTable('types')
            || !Schema::hasColumns('categories', ['image', 'language', 'is_active', 'show_on_homepage', 'homepage_sort_order'])) {
            return;
        }
        $typeId = DB::table('types')->where('slug', 'plants')->orderBy('id')->value('id');
        if (!$typeId) {
            return; // fresh / empty database: nothing to dress
        }

        // DDL commits implicitly in MySQL, so the backup table exists before the transaction.
        if (!Schema::hasTable(self::BACKUP)) {
            Schema::create(self::BACKUP, function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('category_id');
                $t->string('action', 16); // updated | created | restored | unflagged | reordered
                $t->longText('old_image')->nullable();
                $t->text('old_details')->nullable();
                $t->boolean('old_is_active')->nullable();
                $t->boolean('old_show_on_homepage')->nullable();
                $t->integer('old_homepage_sort_order')->nullable();
                $t->timestamp('old_deleted_at')->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }

        try {
            DB::transaction(fn () => $this->apply((int) $typeId));
        } catch (\Throwable $e) {
            // Cosmetic data is never a reason to fail a deploy; the transaction left nothing
            // half-done. Loud in the migrate output and the log, then verified via the API.
            Log::error('apply_owner_category_tiles failed: ' . $e->getMessage());
            echo "WARN apply_owner_category_tiles failed: {$e->getMessage()}\n";
        }

        $this->bustCategoryCache();
    }

    private function apply(int $typeId): void
    {
        $now = now();
        $touched = [];

        foreach (self::TILES as $slug => [$order, $name, $details]) {
            $image = json_encode([
                'id'        => "pah-tile-{$slug}",
                'original'  => self::BASE . "{$slug}-1200.webp",
                'thumbnail' => self::BASE . "{$slug}-600.webp",
            ]);
            $flags = ['image' => $image, 'is_active' => true, 'show_on_homepage' => true, 'homepage_sort_order' => $order, 'updated_at' => $now];

            // A live row wins over a soft-deleted one with the same slug.
            $row = DB::table('categories')
                ->where('type_id', $typeId)->where('slug', $slug)->where('language', 'en')
                ->orderByRaw('deleted_at IS NULL DESC')->orderBy('id')
                ->first();

            if (!$row) {
                $id = DB::table('categories')->insertGetId($flags + [
                    'name' => $name, 'slug' => $slug, 'language' => 'en', 'type_id' => $typeId,
                    'parent' => null, 'icon' => null, 'details' => $details, 'created_at' => $now,
                ]);
                $this->snapshot($id, 'created', null);
                $touched[] = $id;
                continue;
            }

            $this->snapshot($row->id, $row->deleted_at ? 'restored' : 'updated', $row);
            if ($row->deleted_at) {
                $flags['deleted_at'] = null;
            }
            if (is_string($row->details) && str_starts_with(trim($row->details), 'Retired')) {
                $flags['details'] = null;
            }
            DB::table('categories')->where('id', $row->id)->update($flags);
            $touched[] = $row->id;
        }

        $live = fn () => DB::table('categories')->where('type_id', $typeId)->where('language', 'en')->whereNull('deleted_at');

        foreach (self::DUPLICATES as $duplicate => $canonical) {
            $row = $live()->where('slug', $duplicate)->where('show_on_homepage', true)->first();
            $hasCanonical = $live()->where('slug', $canonical)->exists();
            if ($row && $hasCanonical) {
                $this->snapshot($row->id, 'unflagged', $row);
                DB::table('categories')->where('id', $row->id)->update(['show_on_homepage' => false, 'updated_at' => $now]);
                $touched[] = $row->id;
            }
        }

        $others = $live()->whereNull('parent')->where('show_on_homepage', true)
            ->whereNotIn('id', $touched)->where('homepage_sort_order', '<', self::AFTER_TILES)->get();
        foreach ($others as $row) {
            $this->snapshot($row->id, 'reordered', $row);
            DB::table('categories')->where('id', $row->id)->update(['homepage_sort_order' => self::AFTER_TILES, 'updated_at' => $now]);
        }
    }

    private function snapshot(int $categoryId, string $action, ?object $row): void
    {
        DB::table(self::BACKUP)->insert([
            'category_id'             => $categoryId,
            'action'                  => $action,
            'old_image'               => $row?->image,
            'old_details'             => $row?->details,
            'old_is_active'           => $row?->is_active,
            'old_show_on_homepage'    => $row?->show_on_homepage,
            'old_homepage_sort_order' => $row?->homepage_sort_order,
            'old_deleted_at'          => $row?->deleted_at,
            'created_at'              => now(),
        ]);
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::BACKUP)) {
            return;
        }
        DB::transaction(function () {
            foreach (DB::table(self::BACKUP)->orderByDesc('id')->get() as $b) {
                if ($b->action === 'created') {
                    // Soft delete: products may have been put in it since.
                    DB::table('categories')->where('id', $b->category_id)->update(['deleted_at' => now()]);
                    continue;
                }
                DB::table('categories')->where('id', $b->category_id)->update([
                    'image'               => $b->old_image,
                    'details'             => $b->old_details,
                    'is_active'           => $b->old_is_active,
                    'show_on_homepage'    => $b->old_show_on_homepage,
                    'homepage_sort_order' => $b->old_homepage_sort_order,
                    'deleted_at'          => $b->old_deleted_at,
                ]);
            }
        });
        Schema::drop(self::BACKUP);
        $this->bustCategoryCache();
    }

    /** Same key CategoryController::bustCategoryCache bumps. */
    private function bustCategoryCache(): void
    {
        try {
            Cache::forever('categories:ver', (int) Cache::get('categories:ver', 1) + 1);
        } catch (\Throwable $e) {
            // the 600 s cache expires on its own
        }
    }
};
