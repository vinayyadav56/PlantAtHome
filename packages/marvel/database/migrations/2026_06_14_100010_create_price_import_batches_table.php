<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for each admin vendor price-sheet upload: who/which vendor/period,
 * the stored file, row + error counts, and a sample of row errors. Each imported
 * vendor_product_prices row links back via import_batch_id.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('price_import_batches')) {
            return;
        }
        Schema::create('price_import_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('uploaded_by')->nullable()->index();
            $table->unsignedBigInteger('shop_id')->nullable()->index();
            $table->string('period_type')->nullable();        // weekly | monthly
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('file')->nullable();               // stored path
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('errors')->nullable();               // sample of row errors
            $table->string('status')->default('completed');   // completed | failed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_import_batches');
    }
};
