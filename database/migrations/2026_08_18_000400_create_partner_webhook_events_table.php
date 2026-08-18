<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every partner webhook, mirrored from the shipping service's webhook_logs into the monolith so
 * the Partner Orders page can show "every webhook call received", per order, with its payload and
 * what our processing did with it.
 *
 * source_webhook_log_id is UNIQUE — the mirror is idempotent by construction: the ledger sweep can
 * re-read the same page of the service's log forever without duplicating an event. Raw HEADERS
 * stay in the Go service's webhook_logs (already persisted, credential-redacted); this table
 * carries the payload and the processing outcome, and the admin links the two by source id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('partner_webhook_events')) {
            return;
        }
        Schema::create('partner_webhook_events', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('source_webhook_log_id')->unique();
            $t->string('partner_code', 32)->index();
            $t->string('porter_order_id', 191)->nullable()->index();
            $t->unsignedBigInteger('partner_console_order_id')->nullable()->index();
            $t->unsignedBigInteger('shipment_id')->nullable();
            $t->string('event_type', 64)->nullable();
            $t->string('partner_status', 24)->nullable();
            $t->boolean('signature_valid')->default(false);
            $t->json('payload')->nullable();
            // Partner-reported event time. Display-only: Porter UAT sends a bogus constant on
            // order_start_trip (seconds vs ms elsewhere), so ordering NEVER trusts it.
            $t->bigInteger('event_ts')->nullable();
            $t->timestamp('received_at')->nullable();
            $t->timestamp('processed_at')->nullable();
            $t->string('processing_status', 24)->nullable(); // applied | stale | ignored | error
            $t->text('processing_error')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_webhook_events');
    }
};
