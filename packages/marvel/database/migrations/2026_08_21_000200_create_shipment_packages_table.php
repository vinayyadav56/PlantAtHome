<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The physical parcels that make up a shipment.
 *
 * A shipment answers "what is travelling together"; a package answers "what box is it
 * in". Those were the same row until now — weight_g / length_cm / breadth_cm / height_cm
 * live directly on `shipments`, so a vendor sending a plant and a heavy ceramic pot in
 * two boxes could only describe one of them.
 *
 * IMPORTANT, and the reason the flat columns are KEPT rather than moved:
 * ShippingServiceClient::buildRequest() sends the Go shipping-service a single flat
 * parcel (weight_g + one L/B/H triple); there is no packages[] field in that contract.
 * So multiple package rows are recorded here and shown to the operator, but the partner
 * is still booked for ONE parcel until the Go service learns the array. The flat columns
 * on `shipments` are maintained as the rollup that actually gets sent — sum of weights,
 * and the largest single box's dimensions (NOT summed: three boxes do not make one box
 * three times as long, and couriers price on the volumetric weight of each parcel).
 *
 * ponytail: rollup-to-one-parcel; send packages[] once the Go service accepts it.
 *
 * NULL dimensions keep their existing meaning — derive from product data, then the
 * courier settings default, then 20x15x15 — which is why the backfill below creates a
 * row only where an operator had actually recorded something.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shipment_packages')) {
            return;
        }

        Schema::create('shipment_packages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('shipment_id');
            $table->unsignedSmallInteger('package_number')->default(1);
            $table->unsignedInteger('weight_g')->nullable();
            $table->decimal('length_cm', 6, 2)->nullable();
            $table->decimal('breadth_cm', 6, 2)->nullable();
            $table->decimal('height_cm', 6, 2)->nullable();
            $table->decimal('declared_value', 14, 2)->nullable();
            $table->string('contents', 255)->nullable();
            $table->boolean('fragile')->default(false);
            $table->timestamps();

            $table->unique(['shipment_id', 'package_number'], 'shipment_packages_ship_num_unique');
        });

        $this->backfill();
    }

    /**
     * One package per shipment that already carries an operator-corrected parcel. A
     * shipment with all four columns null has no package row: inserting a row of nulls
     * would turn "derive it" into "an empty box was measured".
     */
    private function backfill(): void
    {
        if (!Schema::hasTable('shipments')) {
            return;
        }

        $now = now();
        DB::table('shipments')
            ->where(function ($q) {
                $q->whereNotNull('weight_g')
                    ->orWhereNotNull('length_cm')
                    ->orWhereNotNull('breadth_cm')
                    ->orWhereNotNull('height_cm');
            })
            ->select('id', 'weight_g', 'length_cm', 'breadth_cm', 'height_cm')
            ->orderBy('id')
            ->chunk(500, function ($rows) use ($now) {
                $insert = [];
                foreach ($rows as $row) {
                    $insert[] = [
                        'shipment_id'    => $row->id,
                        'package_number' => 1,
                        'weight_g'       => $row->weight_g,
                        'length_cm'      => $row->length_cm,
                        'breadth_cm'     => $row->breadth_cm,
                        'height_cm'      => $row->height_cm,
                        'created_at'     => $now,
                        'updated_at'     => $now,
                    ];
                }
                if ($insert) {
                    DB::table('shipment_packages')->insert($insert);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_packages');
    }
};
