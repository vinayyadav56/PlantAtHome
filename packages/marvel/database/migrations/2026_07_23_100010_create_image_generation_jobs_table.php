<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per Excel plant line. Workers claim rows atomically
 * (status pending|retrying → processing), so concurrent job chains can
 * never double-generate a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('image_generation_jobs')) {
            return;
        }

        Schema::create('image_generation_jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('batch_id');
            $table->foreign('batch_id')->references('id')->on('image_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');            // Excel line (errors/reporting)
            $table->string('plant_code', 64);
            $table->string('plant_name');
            $table->text('prompt');
            $table->string('folder_name');                    // pre-sanitized PLT001_Areca_Palm

            // pending | retrying | processing | completed | partial | failed | cancelled
            $table->string('status', 32)->default('pending');

            $table->unsignedTinyInteger('images_requested')->default(1);
            $table->unsignedTinyInteger('images_completed')->default(0);
            $table->unsignedTinyInteger('images_failed')->default(0);
            $table->unsignedSmallInteger('attempts')->default(0); // row-level generation attempts
            $table->text('last_error')->nullable();
            $table->timestamp('claimed_at')->nullable();      // stall detection
            $table->timestamps();

            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_generation_jobs');
    }
};
