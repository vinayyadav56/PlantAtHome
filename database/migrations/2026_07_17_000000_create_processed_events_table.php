<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable idempotency ledger for outbox delivery. One row per
 * (event_id, subscriber) that has been successfully processed. The relay checks
 * this before invoking a subscriber and records it after, inside the same
 * transaction as the subscriber's side effect — so at-least-once redelivery
 * (retry, worker restart, second worker) never double-runs a subscriber, and
 * individual subscribers no longer need their own dedupe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('processed_events')) {
            return;
        }

        Schema::create('processed_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('event_id');
            $table->string('subscriber', 191);
            $table->timestamp('processed_at')->nullable();
            $table->unique(['event_id', 'subscriber']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_events');
    }
};
