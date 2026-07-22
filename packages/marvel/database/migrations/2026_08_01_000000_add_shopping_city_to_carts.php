<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shopping-City redesign: a cart belongs to exactly ONE shopping city. Stamp both the
 * id (when the city exists in the `cities` canon) and the display name (the operative
 * key — city matching is name-string based via AvailabilityService::normalizeCityKey).
 * Nullable/additive: old clients that never send a city keep working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->unsignedBigInteger('shopping_city_id')->nullable()->after('items');
            $table->string('shopping_city')->nullable()->after('shopping_city_id');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn(['shopping_city_id', 'shopping_city']);
        });
    }
};
