<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per image slot; the auto-increment id IS the globally unique
 * internal Image ID (display IMG%012d) while the s3 object keeps its clean
 * plant filename. UNIQUE (job_id, image_index) lets retries flip a failed
 * slot to completed in place — the Image ID stays stable and completed
 * slots are never regenerated.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('image_generation_results')) {
            return;
        }

        Schema::create('image_generation_results', function (Blueprint $table) {
            $table->bigIncrements('id'); // display id: IMG%012d
            $table->unsignedBigInteger('batch_id')->index();
            $table->unsignedBigInteger('job_id');
            $table->foreign('job_id')->references('id')->on('image_generation_jobs')->cascadeOnDelete();
            $table->unsignedTinyInteger('image_index'); // 1..images_per_plant

            $table->string('status', 32); // completed | failed
            $table->string('file_name')->nullable();    // PLT001_Areca_Palm_1.png
            $table->string('s3_path')->nullable();
            $table->string('public_url')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();

            $table->string('model', 64)->nullable();
            $table->string('size', 32)->nullable();
            $table->string('quality', 32)->nullable();
            $table->string('style', 64)->nullable();
            $table->text('revised_prompt')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['job_id', 'image_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_generation_results');
    }
};
