<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corporate-gifting enquiries carry a preferred delivery date and plant
 * preferences; neither earns its own column. Additive nullable JSON, guarded
 * for idempotency.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('garden_leads') || Schema::hasColumn('garden_leads', 'meta')) {
            return;
        }
        Schema::table('garden_leads', function (Blueprint $table) {
            $table->json('meta')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('garden_leads') && Schema::hasColumn('garden_leads', 'meta')) {
            Schema::table('garden_leads', function (Blueprint $table) {
                $table->dropColumn('meta');
            });
        }
    }
};
