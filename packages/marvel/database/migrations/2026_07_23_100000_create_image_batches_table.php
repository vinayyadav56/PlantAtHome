<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One background AI image-generation batch (Excel upload). Doubles as the
 * audit row: uploader, full settings snapshot, counters, errors, zip paths.
 * Counters are only ever changed via atomic increments so progress reads
 * never scan the job/result tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('image_batches')) {
            return;
        }

        Schema::create('image_batches', function (Blueprint $table) {
            $table->bigIncrements('id'); // display id: BATCH%06d
            $table->unsignedBigInteger('uploaded_by')->nullable()->index();
            $table->string('notify_email')->nullable();
            $table->string('original_file')->nullable();     // private 'local' disk path
            $table->json('reference_images')->nullable();    // s3 keys under _reference/
            $table->json('settings');                        // model/size/quality/style/background snapshot
            $table->unsignedTinyInteger('images_per_plant')->default(1);

            // pending → processing → packaging → completed | completed_with_errors | failed | cancelled
            $table->string('status', 32)->default('pending')->index();

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('row_error_count')->default(0);
            $table->json('row_errors')->nullable();          // capped sample {line, error}
            $table->unsignedInteger('total_images')->default(0);
            $table->unsignedInteger('generated_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('cancelled_count')->default(0);

            $table->decimal('estimated_cost', 10, 4)->nullable(); // USD

            $table->json('zip_paths')->nullable();           // [{part, path, bytes}]
            $table->string('manifest_path')->nullable();
            $table->unsignedBigInteger('zip_size')->nullable();
            $table->text('zip_error')->nullable();

            $table->timestamp('last_dispatched_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('packaged_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index(); // retention prune
            $table->timestamp('files_pruned_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_batches');
    }
};
