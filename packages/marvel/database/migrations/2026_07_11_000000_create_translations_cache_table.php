<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * translations_cache — the enterprise OVERLAY store.
 *
 * The canonical English row stays the only row on products/categories/etc.;
 * its translated fields live here keyed by (type, id, language) and are merged
 * onto the entity at read time. One polymorphic table means adding a new
 * translatable content-type needs ZERO schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('translations_cache')) {
            return;
        }

        Schema::create('translations_cache', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Polymorphic reference to the canonical English entity.
            $table->string('translatable_type');               // FQCN, e.g. Marvel\Database\Models\Product
            $table->unsignedBigInteger('translatable_id');      // = reference_id (canonical row id)
            $table->string('language', 12);                     // hi, mr, kn, ta, te, …

            // The translated field map, e.g. {"name":"…","description":"…"}.
            $table->json('translated_fields');

            // sha256 of the canonical English source fields — drives versioning:
            // when the source changes the hash differs → mark outdated → requeue.
            $table->char('source_hash', 64)->nullable();

            // Which provider produced this (google/openai/claude/azure/deepl/manual).
            $table->string('translation_source', 32)->default('google');

            // Lifecycle: pending (queued) → translated → outdated (source changed) | failed.
            $table->enum('status', ['pending', 'translated', 'outdated', 'failed'])->default('pending');

            // Human-reviewed flag (protects manual edits from being overwritten).
            $table->boolean('is_reviewed')->default(false);

            // Monotonic version, bumped each time the translation is (re)written.
            $table->unsignedInteger('version')->default(1);

            // Last provider/job error for the admin "failed" view.
            $table->text('last_error')->nullable();

            $table->timestamps();

            // <20ms lookup for the read-overlay (the hot path) + idempotent upserts.
            $table->unique(['translatable_type', 'translatable_id', 'language'], 'txn_cache_unique');

            // Admin coverage / missing / queue scans.
            $table->index(['translatable_type', 'language', 'status'], 'txn_cache_coverage');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations_cache');
    }
};
