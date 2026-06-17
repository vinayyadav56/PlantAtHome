<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Courier integration C1 — physical weight + dimensions on the master product (needed for
 * courier rate + AWB). Additive; defaults to 0, and CourierService falls back to
 * settings.options.courier.default_package when a value is 0, so rate/booking never hard-fail.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'weight')) {
                $table->unsignedInteger('weight')->default(0); // grams
            }
            if (!Schema::hasColumn('products', 'length')) {
                $table->decimal('length', 6, 2)->default(0); // cm
            }
            if (!Schema::hasColumn('products', 'breadth')) {
                $table->decimal('breadth', 6, 2)->default(0);
            }
            if (!Schema::hasColumn('products', 'height')) {
                $table->decimal('height', 6, 2)->default(0);
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }
        Schema::table('products', function (Blueprint $table) {
            foreach (['weight', 'length', 'breadth', 'height'] as $c) {
                if (Schema::hasColumn('products', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
