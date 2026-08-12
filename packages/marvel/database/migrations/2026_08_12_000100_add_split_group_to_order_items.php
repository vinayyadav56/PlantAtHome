<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets ONE vendor's lines be split across MORE THAN ONE shipment.
 *
 * Shipments group by (shop_id, fulfillment_mode), which is the right default —
 * a vendor's items travel together in one booking. But a large order sometimes
 * has to leave in two vehicles, and until now there was no way to express that:
 * regroup() would immediately merge the rows back together.
 *
 * NULL = the default group (today's behaviour, unchanged). A non-null value is
 * an operator-created parcel; grouping becomes (shop_id, mode, split_group).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('split_group', 16)->nullable()->after('shipment_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('split_group');
        });
    }
};
