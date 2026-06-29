<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * translation_string_cache — cost dedupe.
 *
 * Identical source phrases (repeated category names, units, "Indoor Plant", …)
 * are translated ONCE per language and reused forever. Keyed by a hash of
 * (language + source text). This is the core AI-cost optimisation: the same
 * string is never sent to a provider twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('translation_string_cache')) {
            return;
        }

        Schema::create('translation_string_cache', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('source_hash', 64);            // sha256(language . '|' . source)
            $table->string('language', 12);
            $table->mediumText('source_text');
            $table->mediumText('translated_text');
            $table->string('provider', 32)->default('google');
            $table->timestamps();

            $table->unique('source_hash', 'txn_string_unique');
            $table->index('language');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_string_cache');
    }
};
