<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suborders (child orders) are split by vertical. `vertical_type_id` records which
 * vertical (Type: plants/tools/farmbox) a suborder belongs to. Parent orders keep
 * it NULL. Nullable + indexed; guarded.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('orders') && !Schema::hasColumn('orders', 'vertical_type_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedInteger('vertical_type_id')->nullable()->after('shop_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'vertical_type_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('vertical_type_id');
            });
        }
    }
};
