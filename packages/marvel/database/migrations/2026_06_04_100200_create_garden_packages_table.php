<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('garden_packages')) {
            return;
        }
        Schema::create('garden_packages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('lead_id')->nullable()->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('items')->nullable(); // [{category,name,qty,note}]
            $table->integer('total_visits')->default(0);
            $table->decimal('price', 12, 2)->default(0);
            $table->integer('duration_days')->default(30);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status')->default('draft')->index(); // draft/awaiting_payment/active/completed/cancelled
            $table->string('payment_status')->default('unpaid'); // unpaid/paid
            $table->string('razorpay_link_id')->nullable();
            $table->string('razorpay_link_url')->nullable();
            $table->string('razorpay_payment_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('garden_packages');
    }
};
