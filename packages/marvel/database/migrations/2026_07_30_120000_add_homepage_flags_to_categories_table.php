<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Category-level control over the homepage.
 *
 * The homepage collection row used to be curated by a single global slug list in
 * settings.options, so a new category stayed invisible until someone edited
 * settings — and because the row was fetched with no vertical filter, it mixed
 * Plants, Tools and FarmBox categories together. These columns move the decision
 * onto the category itself, where the person creating it already is.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('categories')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table) {
            if (!Schema::hasColumn('categories', 'show_on_homepage')) {
                // Opt-IN, so nothing appears on the homepage by accident.
                $table->boolean('show_on_homepage')->default(false)->after('banner_image');
            }
            if (!Schema::hasColumn('categories', 'homepage_sort_order')) {
                $table->integer('homepage_sort_order')->default(0)->after('show_on_homepage');
            }
            if (!Schema::hasColumn('categories', 'is_active')) {
                // Defaults TRUE: every existing category must keep working. A
                // default of false here would empty the storefront on deploy.
                $table->boolean('is_active')->default(true)->after('homepage_sort_order');
            }
        });

        $this->seedInitialSelection();
    }

    /**
     * Opt in the categories that will actually LOOK right, so the homepage works
     * the moment this ships instead of going blank until someone flags them
     * one at a time.
     *
     * The rule is "root category that already has an image". Measured against
     * live data: plants 14/14 have one, tools 6/10, farm-box 0/4 — and the
     * image-less tools rows are stale duplicates of the ones that do have images
     * ("Pots & Planters" vs "Planters & Pots", "Watering" twice). Selecting on
     * the image therefore also skips the duplicates, which is why it beats
     * "flag everything".
     *
     * FarmBox opts nothing in and its section simply renders no cards until
     * someone adds images — visible and fixable in the admin, rather than a row
     * of empty circles, which is what the old un-filtered query was producing.
     *
     * Only ever runs while every flag is still false, so re-running the
     * migration can never overwrite an operator's later choices.
     */
    private function seedInitialSelection(): void
    {
        try {
            if ((int) DB::table('categories')->where('show_on_homepage', true)->count() > 0) {
                return; // already curated — leave it alone
            }

            DB::table('categories')
                ->whereNull('parent')
                ->whereNotNull('image')
                ->where('image', '<>', '')
                ->whereNull('deleted_at')
                ->update(['show_on_homepage' => true]);
        } catch (\Throwable $e) {
            // Seeding is a convenience, never a reason to fail a deploy. The
            // columns are what matter; an operator can flag categories by hand.
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('categories')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table) {
            foreach (['show_on_homepage', 'homepage_sort_order', 'is_active'] as $column) {
                if (Schema::hasColumn('categories', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
