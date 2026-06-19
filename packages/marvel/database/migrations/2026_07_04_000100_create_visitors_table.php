<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visitor / Live Activity NOC (Phase 3) — one row per anonymous visitor (cookie
 * visitor_id), upserted/touched on every track ping. `last_seen` powers "who's
 * online now"; current/previous page power the live user table. user_id attaches
 * once the visitor logs in.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('visitors')) {
            return;
        }

        Schema::create('visitors', function (Blueprint $table) {
            $table->id();
            $table->string('visitor_id', 64)->unique();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('session_id', 64)->nullable();
            $table->string('ip', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('device', 20)->nullable();   // mobile | desktop | tablet
            $table->string('browser', 40)->nullable();
            $table->string('os', 40)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('country', 80)->nullable();
            $table->string('current_page', 512)->nullable();
            $table->string('previous_page', 512)->nullable();
            $table->string('entry_page', 512)->nullable();
            $table->string('referrer', 512)->nullable();
            $table->unsignedInteger('page_views')->default(0);
            $table->timestamp('first_seen')->nullable();
            $table->timestamp('last_seen')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitors');
    }
};
