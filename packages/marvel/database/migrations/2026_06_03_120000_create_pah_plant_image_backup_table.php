<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot of each plant product's ORIGINAL image/gallery columns before the
 * one-off image repair (point to S3 where it exists, else clear dead Unsplash).
 * Lets the repair be reversed (plantathome:repair-plant-images --rollback).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pah_plant_image_backup')) {
            return;
        }
        Schema::create('pah_plant_image_backup', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->primary();
            $table->json('image')->nullable();
            $table->json('gallery')->nullable();
            $table->timestamp('backed_up_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pah_plant_image_backup');
    }
};
