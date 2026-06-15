<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('care_plan_settings')) {
            return;
        }
        Schema::create('care_plan_settings', function (Blueprint $table) {
            $table->id();
            // Whole feature off by default until ANTHROPIC credits + the Railway service are set.
            $table->boolean('enabled')->default(false);
            // Auto-create a care plan when a plant order is delivered (the headline UX). Honoured
            // only while `enabled` is true.
            $table->boolean('auto_on_delivery')->default(true);
            $table->string('service_url')->nullable();
            $table->string('service_api_key')->nullable(); // seeded via env, never committed
            $table->string('model')->default('claude-opus-4-8');
            $table->decimal('monthly_budget_inr', 10, 2)->default(500);
            $table->timestamps();
        });
        DB::table('care_plan_settings')->insert([
            'enabled' => false,
            'auto_on_delivery' => true,
            'service_url' => null,
            'service_api_key' => null,
            'model' => 'claude-opus-4-8',
            'monthly_budget_inr' => 500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('care_plan_settings');
    }
};
