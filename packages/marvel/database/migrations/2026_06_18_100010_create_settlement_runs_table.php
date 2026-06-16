<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketplace redesign P2 — a settlement sweep. Each run collects all ledger entries
 * that have passed their T+N hold (available_at <= now) and turns them into per-vendor
 * payouts (vendor_settlements). Runs daily (marvel:run-settlements) or on admin demand.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('settlement_runs')) {
            return;
        }
        Schema::create('settlement_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->date('run_date')->index();
            $table->string('status', 16)->default('draft'); // draft | locked
            $table->decimal('total_amount', 16, 2)->default(0);
            $table->unsignedInteger('vendor_count')->default(0);
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_runs');
    }
};
