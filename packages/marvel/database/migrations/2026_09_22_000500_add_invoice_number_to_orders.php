<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A real invoice number.
 *
 * The invoice printed "{prefix}-{tracking_number}", which is not a number in any
 * sense a tax invoice needs: tracking numbers are not sequential, carry no
 * financial-year context, and exist for orders that never became an invoice at
 * all. It also printed TODAY's date as the invoice date, so reprinting last
 * quarter's invoice re-dated it.
 *
 * Numbers come from acc_sequences (row-locked, gapless — the same counter
 * behind journal entries and credit notes) and are minted once, when the order
 * becomes payable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'invoice_number')) {
                $table->string('invoice_number', 24)->nullable()->unique();
            }
            if (!Schema::hasColumn('orders', 'invoice_date')) {
                $table->timestamp('invoice_date')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'invoice_number')) {
                $table->dropUnique(['invoice_number']);
                $table->dropColumn('invoice_number');
            }
            if (Schema::hasColumn('orders', 'invoice_date')) {
                $table->dropColumn('invoice_date');
            }
        });
    }
};
