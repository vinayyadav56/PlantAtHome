<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Units of a line the customer cancelled, so partial cancellation has somewhere to land.
 *
 * The consistency rule the logistics model rests on is
 *
 *     ordered  ==  allocated to shipments  +  cancelled  +  not yet allocated
 *
 * `allocated` is SUM(shipment_items.quantity) and `not yet allocated` is the remainder, so
 * this is the ONE figure that cannot be derived — without it, cancelling 2 of 5 units is
 * indistinguishable from never having planned them. Money-side partials already exist on
 * `orders` (cancelled_amount / cancelled_tax / cancelled_delivery_fee) with no quantity
 * beside them; this is that missing half.
 *
 * Deliberately ONE column, not the four the brief lists: fulfilled and returned counts are
 * derivable from shipment_items.status, and a stored copy of a derivable number is a second
 * source of truth that drifts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('order_items') || Schema::hasColumn('order_items', 'cancelled_quantity')) {
            return;
        }
        Schema::table('order_items', function (Blueprint $t) {
            $t->unsignedInteger('cancelled_quantity')->default(0)->after('order_quantity');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('order_items') || !Schema::hasColumn('order_items', 'cancelled_quantity')) {
            return;
        }
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('cancelled_quantity'));
    }
};
