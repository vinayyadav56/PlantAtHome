<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real-time tracking: a partner's CURRENT position (distinct from lat/lng, which
 * is their onboarding base location). Updated continuously while they're online.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('delivery_partners', function (Blueprint $table) {
            if (!Schema::hasColumn('delivery_partners', 'current_lat')) {
                $table->decimal('current_lat', 10, 7)->nullable();
            }
            if (!Schema::hasColumn('delivery_partners', 'current_lng')) {
                $table->decimal('current_lng', 10, 7)->nullable();
            }
            if (!Schema::hasColumn('delivery_partners', 'location_updated_at')) {
                $table->timestamp('location_updated_at')->nullable();
            }
            if (!Schema::hasColumn('delivery_partners', 'is_online')) {
                $table->boolean('is_online')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('delivery_partners', function (Blueprint $table) {
            foreach (['current_lat', 'current_lng', 'location_updated_at', 'is_online'] as $col) {
                if (Schema::hasColumn('delivery_partners', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
