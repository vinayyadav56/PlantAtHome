<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('plant_doctor_settings')) {
            return;
        }
        Schema::create('plant_doctor_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(true);
            // Microservice connection. The api_key is intentionally left null here
            // (seeded via env PLANT_DOCTOR_SERVICE_API_KEY) so no secret lands in git;
            // an admin can override both from the panel.
            $table->string('service_url')->nullable();
            $table->string('service_api_key')->nullable();
            $table->string('openai_model')->default('gpt-4o-mini');
            $table->decimal('monthly_budget_inr', 10, 2)->default(500);
            $table->boolean('plant_id_enabled')->default(false);
            $table->timestamps();
        });
        DB::table('plant_doctor_settings')->insert([
            'enabled' => true,
            'service_url' => 'https://plantathome-plant-doctor-production.up.railway.app',
            'service_api_key' => null,
            'openai_model' => 'gpt-4o-mini',
            'monthly_budget_inr' => 500,
            'plant_id_enabled' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('plant_doctor_settings');
    }
};
