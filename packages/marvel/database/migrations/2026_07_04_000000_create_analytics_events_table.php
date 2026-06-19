<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visitor / Live Activity NOC (Phase 3) — the immutable behavioural event stream
 * (page_view, product_view, search, add_to_cart, checkout_start, payment_*, etc.)
 * sent from the storefront. Drives the live activity feed, customer-journey
 * timeline and conversion funnel. High-write + append-only → no updated_at, and
 * the ingest path prunes rows older than the retention window.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('analytics_events')) {
            return;
        }

        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->string('visitor_id', 64)->index();
            $table->string('session_id', 64)->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('type', 40)->index(); // page_view | product_view | add_to_cart | checkout_start | payment_complete | ...
            $table->string('url', 512)->nullable();
            $table->string('referrer', 512)->nullable();
            $table->string('label', 255)->nullable();    // e.g. product name / search term
            $table->decimal('value', 14, 2)->nullable(); // e.g. cart / order value
            $table->json('meta')->nullable();
            $table->string('ip', 64)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
    }
};
