<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('plant_doctor_logs')) {
            return;
        }
        Schema::create('plant_doctor_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('session_id')->nullable()->index();
            $table->string('plant_name')->nullable();
            $table->text('condition_summary')->nullable();
            $table->string('top_severity')->nullable();
            $table->decimal('overall_health_score', 4, 2)->default(0);
            $table->string('image_url', 1024)->nullable();
            $table->integer('prompt_tokens')->default(0);
            $table->integer('completion_tokens')->default(0);
            $table->integer('total_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->decimal('cost_inr', 10, 2)->default(0);
            $table->timestamp('created_at')->useCurrent()->index('idx_pd_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plant_doctor_logs');
    }
};
