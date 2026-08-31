<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Part 2 prerequisites.
 *
 * legal_variables: operational values (refund windows, SLAs, penalties) that
 * documents reference as {{key}} instead of hard-coding. Values may be
 * pre-filled as drafting conveniences, but is_approved gates whether they may
 * appear on a PUBLIC page — an unapproved default must never become a
 * customer-facing promise.
 *
 * legal_document_relations: "see the authoritative document" links, so a rule
 * lives in exactly one policy and the others reference it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('legal_variables')) {
            Schema::create('legal_variables', function (Blueprint $table) {
                $table->id();
                $table->string('key', 64)->unique();      // refund_processing_time
                $table->string('label');
                $table->string('value')->nullable();      // "7 business days"
                $table->string('unit', 32)->nullable();
                $table->string('category', 40)->index();  // refunds|delivery|vendor|support|finance|risk
                $table->text('description')->nullable();
                $table->boolean('is_approved')->default(false)->index();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('legal_document_relations')) {
            Schema::create('legal_document_relations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('document_id')->index();
                $table->unsignedBigInteger('related_document_id')->index();
                $table->string('relation_type', 24)->default('related'); // related|references|supersedes
                $table->timestamps();
                $table->unique(['document_id', 'related_document_id'], 'legal_relation_pair_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_document_relations');
        Schema::dropIfExists('legal_variables');
    }
};
