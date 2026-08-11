<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest orders had NO email anywhere — the confirmation link (?token=) lived
 * only in the buyer's localStorage, so clearing the browser orphaned the
 * order. customer_email is an order-time snapshot (same pattern as
 * customer_name / customer_contact); guests may optionally provide it and the
 * order-placed email carries the tokenized tracking link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_email')->nullable()->after('customer_name');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('customer_email');
        });
    }
};
