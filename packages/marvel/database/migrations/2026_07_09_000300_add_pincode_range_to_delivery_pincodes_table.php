<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pincode RANGES — a single delivery_pincodes row can now cover a contiguous
 * range [pincode .. pincode_end] instead of one pincode, so an admin can add a
 * whole city's block without expanding every code. NULL pincode_end keeps the
 * existing single-pincode behaviour. Additive + nullable.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('delivery_pincodes')) {
            return;
        }
        Schema::table('delivery_pincodes', function (Blueprint $table) {
            if (!Schema::hasColumn('delivery_pincodes', 'pincode_end')) {
                $table->string('pincode_end', 12)->nullable()->after('pincode');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('delivery_pincodes')) {
            return;
        }
        Schema::table('delivery_pincodes', function (Blueprint $table) {
            if (Schema::hasColumn('delivery_pincodes', 'pincode_end')) {
                $table->dropColumn('pincode_end');
            }
        });
    }
};
