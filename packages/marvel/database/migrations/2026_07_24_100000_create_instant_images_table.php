<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-off "instant" admin generations (the old Node image-service flow,
 * consolidated into the monolith). Queue-backed: the admin submits, the
 * images-instant worker generates, the admin polls until terminal.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('instant_images')) {
            return;
        }

        Schema::create('instant_images', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('requested_by')->nullable()->index();
            $table->text('prompt');
            $table->json('settings');                     // model/size/quality/style/background
            $table->json('reference_images')->nullable(); // s3 keys
            $table->string('status', 32)->default('pending')->index(); // pending|processing|completed|failed
            $table->string('s3_path')->nullable();
            $table->string('public_url')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->text('revised_prompt')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instant_images');
    }
};
