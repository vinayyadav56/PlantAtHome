<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which revision of the tax engine produced this order's snapshot.
 *
 * The figures on an order are immutable, but "why is this order's freight tax a
 * few paise off that one's" had no answer: both were correct, under different
 * arithmetic. Stamping the version makes a reconciliation across an engine
 * change explainable instead of suspicious.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders') || Schema::hasColumn('orders', 'tax_calc_version')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->string('tax_calc_version', 16)->nullable()->after('seller_state_code');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'tax_calc_version')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('tax_calc_version');
            });
        }
    }
};
