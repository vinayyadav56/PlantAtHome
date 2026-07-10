<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Category tree (Section 4.3). Self-referencing; `path` is a materialized path
 * of ancestor ids (e.g. "/1/5/12/") so a whole subtree is a single indexed
 * LIKE query. `depth` is the 0-based level. Admin-owned; no nursery link.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('catalog_categories')) {
            return;
        }

        Schema::create('catalog_categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('path')->default('/')->index();   // materialized ancestor ids
            $table->unsignedSmallInteger('depth')->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->string('status')->default('active');     // active | inactive
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('parent_id')->references('id')->on('catalog_categories')->nullOnDelete();
            $table->index(['parent_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_categories');
    }
};
