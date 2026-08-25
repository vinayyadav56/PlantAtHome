<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Centralized media system: media_items is the stable identity (uuid survives
 * environment DB refreshes), media_item_versions are IMMUTABLE pointers at S3
 * objects (a change to the actual image = a new version, never an overwrite),
 * media_attachments bind items to entities with role + ordering (reordering
 * never creates versions).
 *
 * live_version_id has no FK on purpose — circular with media_item_versions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_items', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('uuid')->unique();
            // Path hint only ('products', 'categories', …) — real entity
            // linkage lives in media_attachments.
            $t->string('entity_hint', 40)->nullable();
            $t->unsignedBigInteger('live_version_id')->nullable()->index();
            $t->string('origin_env', 10)->default('production');
            $t->unsignedBigInteger('created_by')->nullable();
            // alt, source, attribution, adoption markers…
            $t->json('meta')->nullable();
            $t->timestamps();
        });

        Schema::create('media_item_versions', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('media_item_id');
            $t->foreign('media_item_id')->references('id')->on('media_items')->restrictOnDelete();
            $t->unsignedInteger('version_number');
            // draft|processing|ready|approved|live|rejected|retired
            $t->string('status', 12)->default('draft');
            $t->string('origin_env', 10);
            // The immutable S3 key of the original upload. Nullable for adopted
            // external URLs (CC-sourced images living on foreign hosts).
            $t->string('original_key', 700)->nullable();
            // Absolute URL for external (non-bucket) originals only.
            $t->string('external_url', 1000)->nullable();
            // {thumbnail: key, small: key, medium: key, large: key}
            $t->json('variants')->nullable();
            $t->string('mime', 100)->nullable();
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->char('checksum', 64)->nullable();
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamp('retired_at')->nullable();
            // Physical S3 deletion record — the row itself is the audit trail.
            $t->timestamp('purged_at')->nullable();
            $t->string('rejection_reason', 500)->nullable();
            $t->timestamps();
            $t->unique(['media_item_id', 'version_number']);
            $t->index(['status', 'retired_at']);
            $t->index(['origin_env', 'status']);
        });

        Schema::create('media_attachments', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('media_item_id');
            $t->foreign('media_item_id')->references('id')->on('media_items')->cascadeOnDelete();
            $t->string('attachable_type', 120);
            $t->unsignedBigInteger('attachable_id');
            // main|gallery|banner|logo|cover|…
            $t->string('role', 30)->default('gallery');
            $t->integer('position')->default(0);
            $t->timestamps();
            $t->index(['attachable_type', 'attachable_id', 'role', 'position'], 'media_attachments_entity_idx');
            $t->unique(['attachable_type', 'attachable_id', 'media_item_id', 'role'], 'media_attachments_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_attachments');
        Schema::dropIfExists('media_item_versions');
        Schema::dropIfExists('media_items');
    }
};
