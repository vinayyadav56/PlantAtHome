<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cursor state for legacy→v2 backfill commands (v2:backfill-*). One row per
 * resource; powers --since-cursor tailing sync for tables the shop keeps
 * writing after the admin cuts a resource over to v2.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('backfill_cursors')) {
            return;
        }

        Schema::create('backfill_cursors', function (Blueprint $table) {
            $table->id();
            $table->string('resource')->unique();
            $table->timestamp('last_source_updated_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedBigInteger('rows_upserted')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backfill_cursors');
    }
};
