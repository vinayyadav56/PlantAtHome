<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('care_plans')) {
            return;
        }
        Schema::create('care_plans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->string('plant_name')->nullable();
            $table->string('scientific_name')->nullable();
            $table->string('language', 12)->default('en');
            $table->string('image_url')->nullable();
            // Full AI care plan (watering/light/humidity/fertilizer/tips/...).
            $table->json('plan_json')->nullable();
            $table->string('status')->default('active'); // active | archived
            $table->string('source')->default('delivery'); // delivery | manual
            $table->timestamp('last_checkup_at')->nullable();
            // Cost accounting (mirrors plant_doctor_logs).
            $table->integer('total_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->decimal('cost_inr', 12, 4)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_plans');
    }
};
