<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gifting tiers are now fully backend-managed from the admin B2B section.
 * `badge` replaces the storefront's hardcoded "PREMIUM on the 3rd card" rule —
 * a tier is highlighted iff it carries a badge.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('garden_package_templates') && !Schema::hasColumn('garden_package_templates', 'badge')) {
            Schema::table('garden_package_templates', function (Blueprint $table) {
                $table->string('badge', 50)->nullable()->after('tagline');
            });
        }

        // Preserve the current storefront look: the premium seed tier keeps its badge.
        DB::table('garden_package_templates')
            ->where('service', 'gifting')
            ->where('name', 'Premium Curated Hampers')
            ->whereNull('badge')
            ->update(['badge' => 'Premium']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('garden_package_templates', 'badge')) {
            Schema::table('garden_package_templates', function (Blueprint $table) {
                $table->dropColumn('badge');
            });
        }
    }
};
