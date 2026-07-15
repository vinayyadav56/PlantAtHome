<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor-profile fields the legacy admin create form sends but v2 dropped:
 * contact/mobile/upi, geo point, category + service-area snapshots (json —
 * the legacy pivots stay the source the storefront reads), and the gst_number
 * mirror of settings.compliance.gst. Guarded per column: staging already ran
 * partial versions of nursery migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nursery_nurseries', function (Blueprint $table) {
            if (! Schema::hasColumn('nursery_nurseries', 'contact_person')) {
                $table->string('contact_person')->nullable();
            }
            if (! Schema::hasColumn('nursery_nurseries', 'mobile')) {
                $table->string('mobile')->nullable();
            }
            if (! Schema::hasColumn('nursery_nurseries', 'upi')) {
                $table->string('upi')->nullable();
            }
            if (! Schema::hasColumn('nursery_nurseries', 'lat')) {
                $table->decimal('lat', 10, 7)->nullable();
            }
            if (! Schema::hasColumn('nursery_nurseries', 'lng')) {
                $table->decimal('lng', 10, 7)->nullable();
            }
            if (! Schema::hasColumn('nursery_nurseries', 'category_ids')) {
                $table->json('category_ids')->nullable();
            }
            if (! Schema::hasColumn('nursery_nurseries', 'service_areas')) {
                $table->json('service_areas')->nullable();
            }
            if (! Schema::hasColumn('nursery_nurseries', 'gst_number')) {
                $table->string('gst_number')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('nursery_nurseries', function (Blueprint $table) {
            foreach (['contact_person', 'mobile', 'upi', 'lat', 'lng', 'category_ids', 'service_areas', 'gst_number'] as $column) {
                if (Schema::hasColumn('nursery_nurseries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
