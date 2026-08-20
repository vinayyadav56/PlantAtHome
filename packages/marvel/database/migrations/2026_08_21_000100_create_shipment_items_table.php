<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHICH UNITS of an order line travel on WHICH shipment.
 *
 * Until now the link was `order_items.shipment_id` — a scalar FK, so a line belonged to
 * exactly one parcel. That makes quantity-level fulfilment structurally impossible: a
 * "Jade Plant x 5" that has to leave as 3 + 2 has nowhere to record the 3 or the 2, and
 * splitShipment() could only ever move WHOLE lines. Every partial-fulfilment concept
 * downstream (partial delivery, partial cancellation, per-parcel COD) inherited the same
 * ceiling — a grep for fulfilled_quantity / cancelled_quantity / shipped_qty across the
 * whole app returns nothing, because none of them could be expressed.
 *
 * This table is the allocation ledger and becomes the source of truth. `shipment_id` on
 * order_items stays, DERIVED: the single shipment when a line is wholly in one parcel,
 * NULL when it is split. NULL rather than "the first one" on purpose — a reader that has
 * not been migrated then shows the line nowhere instead of showing all 5 units twice,
 * and undercounting is the safe direction for a courier payload.
 *
 * `status` mirrors order_items.item_status per parcel, which is what lets one leg be
 * delivered while its sibling is still in transit.
 *
 * No FKs: this codebase does not FK shops anywhere, the test connection runs with
 * foreign_key_constraints=false, and regroup() deletes/recreates these rows in bulk.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shipment_items')) {
            return;
        }

        Schema::create('shipment_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('shipment_id');
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedInteger('quantity')->default(1);
            $table->string('status', 32)->default('pending');
            $table->timestamps();

            // Named explicitly: the generated name exceeds MySQL's 64-char identifier limit.
            $table->unique(['shipment_id', 'order_item_id'], 'shipment_items_ship_item_unique');
            $table->index('order_item_id');
        });

        $this->backfill();
    }

    /**
     * One allocation per existing (item -> shipment) pair, carrying the whole ordered
     * quantity — which is exactly what the scalar column meant. Chunked because this runs
     * on live boxes; idempotent by construction, since up() returns early once the table
     * exists.
     */
    private function backfill(): void
    {
        if (!Schema::hasTable('order_items')) {
            return;
        }

        $now = now();
        DB::table('order_items')
            ->whereNotNull('shipment_id')
            ->select('id', 'shipment_id', 'order_quantity', 'item_status')
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($now) {
                $insert = [];
                foreach ($rows as $row) {
                    $insert[] = [
                        'shipment_id'   => $row->shipment_id,
                        'order_item_id' => $row->id,
                        'quantity'      => max(1, (int) $row->order_quantity),
                        'status'        => (string) ($row->item_status ?: 'pending'),
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ];
                }
                if ($insert) {
                    DB::table('shipment_items')->insert($insert);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_items');
    }
};
