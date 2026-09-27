<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The delivery method REQUESTED at order creation (admin custom orders:
 * 'local' | 'standard_interstate' — slug vocabulary, extensible). Recorded
 * intent for ops; fulfillment lanes are still decided at assignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('orders', 'delivery_method')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('delivery_method', 40)->nullable()->after('delivery_time');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'delivery_method')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('delivery_method');
            });
        }
    }
};
