<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Market Intelligence — one price point per watchlist item per refresh. Charted
 * as a time series on the admin Market Intelligence page.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('market_price_snapshots')) {
            return;
        }
        Schema::create('market_price_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('watchlist_id')->index();
            $table->decimal('price_current', 12, 2)->nullable();
            $table->decimal('price_mrp', 12, 2)->nullable();
            $table->decimal('discount_percent', 6, 2)->nullable();
            $table->timestamp('captured_at')->useCurrent()->index('idx_mps_captured');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('watchlist_id')->references('id')->on('market_watchlist')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_price_snapshots');
    }
};
