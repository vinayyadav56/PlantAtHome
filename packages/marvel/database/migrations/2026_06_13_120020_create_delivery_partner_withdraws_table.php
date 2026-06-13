<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Withdrawal requests by delivery partners — mirrors marvel's `withdraws` status
 * machine. Schema lands in P1; the request/approve UI + crediting is P4.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('delivery_partner_withdraws')) {
            return;
        }
        Schema::create('delivery_partner_withdraws', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('delivery_partner_id')->index();
            $table->decimal('amount', 14, 2);
            $table->string('status')->default('pending');   // pending|approved|on_hold|rejected|processing
            $table->string('payment_method')->nullable();
            $table->text('details')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_partner_withdraws');
    }
};
