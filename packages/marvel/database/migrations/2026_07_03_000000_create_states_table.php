<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master Location System (Phase 2) — the canonical list of states. Until now
 * "state" was a free-text string on every address; this gives address forms a
 * real dropdown source and lets cities roll up to a state.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('states')) {
            return;
        }

        Schema::create('states', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 8)->nullable()->index(); // e.g. 'MH', 'DL'
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('states');
    }
};
