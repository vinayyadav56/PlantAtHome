<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * translation_provider_configs — admin-managed, encrypted provider credentials.
 *
 * Mirrors courier_partner_configs: `credentials` is encrypted at rest (APP_KEY)
 * and never serialized. Exactly one row is `is_active` and drives TranslationManager.
 * Switching providers is an admin setting change — no deploy, no code change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('translation_provider_configs')) {
            return;
        }

        Schema::create('translation_provider_configs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('provider', 32);                 // google | openai | claude | azure | deepl
            $table->boolean('enabled')->default(false);
            $table->boolean('is_active')->default(false);   // the single chosen provider
            $table->text('credentials')->nullable();        // encrypted:array (api_key, project_id, region…)
            $table->json('settings')->nullable();           // non-secret (model name, endpoint, …)
            $table->timestamps();

            $table->unique('provider');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_provider_configs');
    }
};
