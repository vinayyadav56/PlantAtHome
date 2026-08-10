<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orders created through the courier partner test console (test/book). The Go service creates a REAL
 * partner order but keeps no local record of a console probe, so without this the provider_order_id
 * (e.g. Porter CRN…) is lost on navigation. One row per (partner, provider_order_id); refreshed with
 * the latest polled status on track.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('partner_console_orders')) {
            return;
        }
        Schema::create('partner_console_orders', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('partner_code', 32)->index();
            $t->string('provider_order_id', 191);
            $t->string('mode', 32)->nullable();
            $t->unsignedBigInteger('cod_amount_paise')->default(0);
            $t->json('request')->nullable();
            $t->json('response')->nullable();
            $t->string('last_status', 64)->nullable();
            $t->timestamp('last_tracked_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->unique(['partner_code', 'provider_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_console_orders');
    }
};
