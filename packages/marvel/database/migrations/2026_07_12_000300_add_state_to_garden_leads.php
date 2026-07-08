<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Garden-service lead form now captures State alongside City (State→City
 * dropdowns). Additive nullable column, guarded for idempotency.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('garden_leads') || Schema::hasColumn('garden_leads', 'state')) {
            return;
        }
        Schema::table('garden_leads', function (Blueprint $table) {
            $table->string('state')->nullable()->after('city');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('garden_leads') && Schema::hasColumn('garden_leads', 'state')) {
            Schema::table('garden_leads', function (Blueprint $table) {
                $table->dropColumn('state');
            });
        }
    }
};
