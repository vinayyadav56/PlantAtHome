<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketplace redesign P3 — vendor quality signals for the per-item assignment score:
 *   vendor_rating / vendor_rating_count → rolling fulfilment rating (0..5)
 *   vendor_priority_score               → admin/algorithmic tier weight (0..100, default 50)
 *   sla_default_days                    → the vendor's default fulfilment SLA
 * All additive; the assignment engine treats missing values as neutral defaults.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('shops')) {
            return;
        }
        Schema::table('shops', function (Blueprint $table) {
            if (!Schema::hasColumn('shops', 'vendor_rating')) {
                $table->decimal('vendor_rating', 3, 2)->nullable()->after('is_active');
            }
            if (!Schema::hasColumn('shops', 'vendor_rating_count')) {
                $table->unsignedInteger('vendor_rating_count')->default(0)->after('vendor_rating');
            }
            if (!Schema::hasColumn('shops', 'vendor_priority_score')) {
                $table->unsignedSmallInteger('vendor_priority_score')->default(50)->after('vendor_rating_count');
            }
            if (!Schema::hasColumn('shops', 'sla_default_days')) {
                $table->unsignedSmallInteger('sla_default_days')->nullable()->after('vendor_priority_score');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('shops')) {
            return;
        }
        Schema::table('shops', function (Blueprint $table) {
            foreach (['vendor_rating', 'vendor_rating_count', 'vendor_priority_score', 'sla_default_days'] as $col) {
                if (Schema::hasColumn('shops', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
