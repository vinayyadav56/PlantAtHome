<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F3 — Pin orders. Admins/shop owners can pin important orders so they float to
 * the top of every order listing (board, list, detail siblings). `is_pinned`
 * drives the ordering; `pinned_at` breaks ties so the most-recently-pinned wins.
 * Indexed for the pinned-first ORDER BY added to the Order model global scope.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'is_pinned')) {
                $table->boolean('is_pinned')->default(false)->index()->after('order_status');
            }
            if (!Schema::hasColumn('orders', 'pinned_at')) {
                $table->timestamp('pinned_at')->nullable()->after('is_pinned');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'is_pinned')) {
                $table->dropColumn('is_pinned');
            }
            if (Schema::hasColumn('orders', 'pinned_at')) {
                $table->dropColumn('pinned_at');
            }
        });
    }
};
