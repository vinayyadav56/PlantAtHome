<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Market Intelligence — price watchlist. Each row is a competitor listing the
 * admin chose to track; price points land in market_price_snapshots on refresh.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('market_watchlist')) {
            return;
        }
        Schema::create('market_watchlist', function (Blueprint $table) {
            $table->id();
            $table->string('doc_id')->unique();            // competitor listing id, e.g. "nurserylive:1783507867-2989"
            $table->string('title');
            $table->string('source_site')->nullable()->index();
            $table->decimal('last_price', 12, 2)->nullable();
            $table->decimal('last_price_mrp', 12, 2)->nullable();
            $table->decimal('last_discount_percent', 6, 2)->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_watchlist');
    }
};
