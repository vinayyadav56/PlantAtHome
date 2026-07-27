<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fine-tuned Flux LoRA styles for the admin instant generator. One row per
 * training run on Replicate (ostris/flux-dev-lora-trainer): the admin uploads
 * training images, we zip + submit, Replicate trains into a private
 * destination model, and once `replicate_version` lands the model becomes a
 * selectable style for provider=replicate instant generations.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_lora_models')) {
            return;
        }

        Schema::create('ai_lora_models', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 60);
            $table->string('trigger_word', 20);              // e.g. PAHSTYLE — prepended to prompts
            $table->string('replicate_model');               // destination "owner/name"
            $table->string('replicate_version')->nullable(); // version hash once training succeeds
            $table->string('replicate_training_id')->nullable();
            $table->string('status', 32)->default('pending')->index(); // pending|training|succeeded|failed|canceled
            $table->unsignedInteger('steps')->default(1000);
            $table->unsignedInteger('lora_rank')->default(16);
            $table->unsignedInteger('image_count')->default(0);
            $table->json('settings')->nullable();            // training image URLs + captions + zip URL
            $table->text('error')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_lora_models');
    }
};
