<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * City landing pages (/plants-in/{slug} on the storefront).
 *
 * One row per genuinely serviceable city — created deliberately (admin or the
 * plantathome:seed-location-pages command, which gates on real supply in
 * product_city_availability), never blindly for every cities row. The slug is
 * derived from the CANONICAL city key (AvailabilityService::canonicalCityKey),
 * so gurgaon/bengaluru aliases collapse to one page and Delhi's NCT districts
 * never get their own.
 *
 * city_name is stored denormalized because the storefront's city-scoped
 * product queries take the NAME (product_city_availability.city holds keys
 * with spaces, e.g. "navi mumbai"), not the hyphenated slug.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('location_pages')) {
            Schema::create('location_pages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('city_id')->index();
                $table->string('slug', 191)->unique();
                $table->string('city_name', 191);
                $table->string('state_name', 191)->nullable();
                $table->string('seo_title', 255)->nullable();
                $table->string('seo_description', 500)->nullable();
                $table->text('intro_html')->nullable();
                $table->text('delivery_html')->nullable();
                $table->json('faqs')->nullable();          // [{question, answer}]
                $table->boolean('is_active')->default(false)->index();
                $table->boolean('is_indexable')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
            });
        }

        // Per-entity noindex switches for the admin SEO sections. seo_title /
        // seo_description already exist on both tables; this completes the set.
        foreach (['products', 'categories'] as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'noindex')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->boolean('noindex')->default(false)->after('seo_description');
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('location_pages');
        foreach (['products', 'categories'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'noindex')) {
                Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('noindex'));
            }
        }
    }
};
