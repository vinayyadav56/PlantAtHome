<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('ai_chat_settings')) {
            return;
        }
        Schema::create('ai_chat_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            // Microservice connection. service_api_key doubles as the shared
            // secret the service signs its persist callback with; left null in
            // git (seeded via env AI_CHAT_SERVICE_API_KEY), admin-overridable.
            $table->string('service_url')->nullable();
            $table->string('service_api_key')->nullable();
            $table->string('openai_model')->default('gpt-4o-mini');
            $table->decimal('monthly_budget_inr', 10, 2)->default(500);
            $table->unsignedSmallInteger('max_prompts')->default(10);
            $table->unsignedSmallInteger('daily_user_cap')->default(60);
            $table->timestamps();
        });
        DB::table('ai_chat_settings')->insert([
            'enabled' => false,
            'service_url' => 'https://plantathome-chatbot-production.up.railway.app',
            'service_api_key' => null,
            'openai_model' => 'gpt-4o-mini',
            'monthly_budget_inr' => 500,
            'max_prompts' => 10,
            'daily_user_cap' => 60,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_settings');
    }
};
