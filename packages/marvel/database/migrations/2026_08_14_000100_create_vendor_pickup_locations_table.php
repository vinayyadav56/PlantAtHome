<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A vendor's physical pickup points, one row per partner pickup location.
 *
 * Until now a vendor had exactly ONE implicit location — shops.pickup_location_name,
 * a bare nickname string with no address of its own and no state. That could not
 * express a nursery that loads from two yards, and it could not record whether the
 * nickname was ever actually accepted by the partner: syncPickupLocation() writes the
 * nickname on success and nothing at all on failure, so "never registered" and
 * "registration failed last Tuesday" look identical, and the operator only finds out
 * at the 422 on a real booking.
 *
 * provider_pickup_code is Shiprocket's own id for the location (addpickup returns
 * address.pickup_code); we decode the response today and throw it away.
 *
 * shop_id carries no FK — this codebase does not FK shops anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vendor_pickup_locations')) {
            return;
        }

        Schema::create('vendor_pickup_locations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('shop_id');
            $table->string('label', 64);                 // the operator's name for this door
            $table->string('contact_name', 96)->nullable();
            $table->string('phone', 24)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('address_2', 255)->nullable();
            $table->string('city', 96)->nullable();
            $table->string('state', 96)->nullable();
            $table->string('country', 64)->default('India');
            $table->string('pincode', 12)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();

            $table->string('partner', 24)->default('shiprocket');
            $table->string('provider_location_name', 64)->nullable(); // sent as `pickup_location`
            $table->string('provider_pickup_code', 64)->nullable();   // Shiprocket address.pickup_code

            // Plain varchar, no DB constraint — same as shops.approval_status. Widening an
            // enum is a table rewrite and partners keep inventing states.
            $table->string('status', 16)->default('pending'); // pending|created|verified|failed|disabled
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['shop_id', 'partner', 'label']);
            $table->index(['shop_id', 'partner', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_pickup_locations');
    }
};
