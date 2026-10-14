<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Category placements: one category listed under more than one parent (owner 2026-10-14,
 * "one page, in every branch" — Root Hooks sits under Potting, Bonsai and Repotting tools).
 * A category keeps ONE primary parent (`categories.parent`: its URL, breadcrumb and
 * ancestry); a placement only adds it to another parent's children and product roll-up.
 *
 * Also adds the missing unique (slug, language) index on categories: /c/{slug} resolves a
 * category by slug alone, so two rows with one slug make one of them unreachable. Skipped
 * and logged when duplicates already exist: a missing safety index is recoverable, a failed
 * migration chain is not (the rule of 2026_08_18_000500_add_unique_index_to_product_slugs).
 */
return new class extends Migration {
    private const INDEX = 'categories_slug_language_unique';

    public function up(): void
    {
        if (!Schema::hasTable('categories')) {
            return;
        }

        if (!Schema::hasTable('category_placements')) {
            Schema::create('category_placements', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('category_id');
                $t->unsignedBigInteger('parent_id');
                $t->integer('display_order')->default(0);
                $t->timestamps();
                $t->unique(['category_id', 'parent_id']);
                $t->index('parent_id');
                $t->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');
                $t->foreign('parent_id')->references('id')->on('categories')->onDelete('cascade');
            });
        }

        $this->addSlugIndex();
    }

    private function addSlugIndex(): void
    {
        try {
            $dupes = DB::table('categories')->select('slug', 'language')
                ->groupBy('slug', 'language')->havingRaw('COUNT(*) > 1')->limit(5)->get();
            if ($dupes->isNotEmpty()) {
                Log::warning('categories (slug, language) unique index skipped: duplicate slugs', ['sample' => $dupes->all()]);
                echo "WARN categories (slug, language) unique index skipped: duplicate slugs exist\n";
                return;
            }
            Schema::table('categories', function (Blueprint $t) {
                $t->unique(['slug', 'language'], self::INDEX);
            });
        } catch (\Throwable $e) {
            // Already present, or the driver refused it: never fail a deploy over a safety index.
            Log::warning('categories (slug, language) unique index not added: ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('category_placements');
        try {
            Schema::table('categories', fn (Blueprint $t) => $t->dropUnique(self::INDEX));
        } catch (\Throwable $e) {
            // never added
        }
    }
};
