<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transactional outbox (architecture plan Section 9). Domain events are written
 * here in the SAME transaction as the business change; a relay worker delivers
 * pending rows to subscribers and marks them published. event_id is unique so a
 * retried producer can insertOrIgnore without duplicating.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('outbox')) {
            return;
        }

        Schema::create('outbox', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('event_id')->unique();
            $table->string('event_name')->index();
            $table->unsignedSmallInteger('version')->default(1);
            $table->json('payload')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->string('occurred_at');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            // Hot path: the relay claims pending rows oldest-first.
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox');
    }
};
