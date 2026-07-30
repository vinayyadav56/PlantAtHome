<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bulk AI product-content generation (admin listing → "Generate with AI").
 * One `product_content_batches` row per run + one `product_content_jobs` row
 * per selected product. Counters are mutated only via atomic increments so
 * progress needs no row scans (mirrors image_batches).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('product_content_batches')) {
            Schema::create('product_content_batches', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->json('options')->nullable();          // {generate:[], length, tone, language, category_mode, overwrite, only_missing}
                $table->string('status')->default('pending')->index();
                $table->unsignedInteger('total_rows')->default(0);
                $table->unsignedInteger('processed_count')->default(0);
                $table->unsignedInteger('updated_count')->default(0);
                $table->unsignedInteger('skipped_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->float('estimated_cost')->nullable();
                $table->json('row_errors')->nullable();        // capped sample
                $table->timestamp('last_dispatched_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('product_content_jobs')) {
            Schema::create('product_content_jobs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('batch_id');
                $table->unsignedBigInteger('product_id');
                $table->string('product_name')->nullable();
                $table->string('status')->default('pending');
                $table->unsignedInteger('attempts')->default(0);
                $table->text('last_error')->nullable();
                $table->json('changes')->nullable();           // {description_updated, categories_added:[]}
                $table->timestamp('claimed_at')->nullable();
                $table->timestamps();

                $table->index(['batch_id', 'status']);
                $table->index('product_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_content_jobs');
        Schema::dropIfExists('product_content_batches');
    }
};
