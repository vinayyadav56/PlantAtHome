<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shopping-City redesign — address upgrades (all nullable/additive; the legacy `address`
 * JSON blob keeps carrying street/line2/landmark/city/state/zip for back-compat):
 * - address_type: home | office | other (the old free-text `type` billing/shipping stays).
 * - recipient_name/phone: "Deliver to Someone Else" recipients saved to the address book.
 * - rg_*: SERVER-verified reverse-geocoded components (from the draggable map pin via
 *   GET geo/reverse — never trusted from the client). rg_city is the authoritative city
 *   used for the shopping-city checkout gate and saved-address grouping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('address', function (Blueprint $table) {
            $table->string('address_type', 20)->nullable()->default('home')->after('type');
            $table->string('recipient_name')->nullable()->after('address_type');
            $table->string('recipient_phone', 32)->nullable()->after('recipient_name');
            $table->string('rg_city')->nullable()->after('longitude');
            $table->string('rg_district')->nullable()->after('rg_city');
            $table->string('rg_state')->nullable()->after('rg_district');
            $table->string('rg_pincode', 12)->nullable()->after('rg_state');
            $table->index('rg_city');
        });
    }

    public function down(): void
    {
        Schema::table('address', function (Blueprint $table) {
            $table->dropIndex(['rg_city']);
            $table->dropColumn([
                'address_type', 'recipient_name', 'recipient_phone',
                'rg_city', 'rg_district', 'rg_state', 'rg_pincode',
            ]);
        });
    }
};
