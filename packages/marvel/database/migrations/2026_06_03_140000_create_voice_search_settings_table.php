<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('voice_search_settings')) {
            return;
        }
        Schema::create('voice_search_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(true);
            $table->decimal('monthly_budget_inr', 10, 2)->default(500);
            $table->string('openai_model')->default('gpt-4o-mini');
            $table->timestamps();
        });
        // Single row: ensure one settings record exists.
        DB::table('voice_search_settings')->insert([
            'enabled' => true,
            'monthly_budget_inr' => 500,
            'openai_model' => 'gpt-4o-mini',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_search_settings');
    }
};
