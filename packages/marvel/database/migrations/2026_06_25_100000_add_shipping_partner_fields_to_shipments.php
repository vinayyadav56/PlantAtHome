<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-partner shipping (Borzo instant/same-city + Shiprocket courier) — additive, nullable,
 * guarded. Adds the lane (`mode`) + per-shipment economics (booked_cost = paid to partner,
 * booked_revenue = charged to customer) + cancel/failure context to `shipments`, and a
 * `delivery_quotes` audit table (every quote we fetched, for book-against-quote + analytics).
 * Existing shipments and the Shiprocket flow are untouched.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('shipments')) {
            Schema::table('shipments', function (Blueprint $table) {
                if (!Schema::hasColumn('shipments', 'mode')) {
                    $table->string('mode', 16)->nullable()->after('fulfillment_mode'); // instant|same_city|courier
                }
                if (!Schema::hasColumn('shipments', 'booked_cost')) {
                    $table->decimal('booked_cost', 14, 2)->nullable();    // what we pay the partner
                }
                if (!Schema::hasColumn('shipments', 'booked_revenue')) {
                    $table->decimal('booked_revenue', 14, 2)->nullable(); // what we charged the customer
                }
                if (!Schema::hasColumn('shipments', 'failure_reason')) {
                    $table->string('failure_reason', 255)->nullable();
                }
                if (!Schema::hasColumn('shipments', 'cancelled_reason')) {
                    $table->string('cancelled_reason', 255)->nullable();
                }
                if (!Schema::hasColumn('shipments', 'cancelled_at')) {
                    $table->timestamp('cancelled_at')->nullable();
                }
            });

            try {
                Schema::table('shipments', fn (Blueprint $t) => $t->index(['provider', 'status']));
            } catch (\Throwable $e) {
                // already indexed
            }
        }

        if (!Schema::hasTable('delivery_quotes')) {
            Schema::create('delivery_quotes', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('shipment_id')->nullable();
                $table->unsignedBigInteger('order_id')->nullable();
                $table->string('partner', 24);                 // borzo | shiprocket | ...
                $table->string('mode', 16)->nullable();        // instant | same_city | courier
                $table->string('pickup_pincode', 12)->nullable();
                $table->string('drop_pincode', 12)->nullable();
                $table->unsignedInteger('weight_g')->nullable();
                $table->boolean('cod')->default(false);
                $table->decimal('cod_amount', 14, 2)->nullable();
                $table->decimal('quoted_cost', 14, 2)->nullable();
                $table->unsignedSmallInteger('quoted_eta_days')->nullable();
                $table->string('currency', 3)->default('INR');
                $table->json('raw')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->index(['shipment_id']);
                $table->index(['order_id']);
                $table->index(['partner', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_quotes');
        if (Schema::hasTable('shipments')) {
            Schema::table('shipments', function (Blueprint $table) {
                foreach (['mode', 'booked_cost', 'booked_revenue', 'failure_reason', 'cancelled_reason', 'cancelled_at'] as $c) {
                    if (Schema::hasColumn('shipments', $c)) {
                        $table->dropColumn($c);
                    }
                }
            });
        }
    }
};
