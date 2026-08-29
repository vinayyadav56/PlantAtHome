<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Legal & Operations document governance. All tables prefixed legal_*.
 * Published versions are immutable rows; the document's current_version_id is
 * a pointer flip (same discipline as media_item_versions). Guarded per-table
 * so re-runs on a partially-migrated env are safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('legal_categories')) {
            Schema::create('legal_categories', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->unsignedBigInteger('parent_id')->nullable()->index();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('legal_document_types')) {
            Schema::create('legal_document_types', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code_prefix', 8)->unique(); // POL, SOP, AGR ...
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('legal_documents')) {
            Schema::create('legal_documents', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('title');
                $table->string('slug')->unique();
                $table->string('document_code', 64)->unique(); // PAH-POL-CUST-001
                $table->unsignedBigInteger('type_id')->index();
                $table->unsignedBigInteger('category_id')->nullable()->index();
                $table->string('department', 64)->nullable();
                $table->unsignedBigInteger('owner_id')->nullable()->index();
                $table->string('status', 24)->default('draft')->index();
                $table->string('visibility', 16)->default('internal')->index();
                // Pointer to the live published version; content lives on versions.
                $table->unsignedBigInteger('current_version_id')->nullable();
                $table->boolean('legal_review_required')->default(false);
                $table->date('effective_date')->nullable();
                $table->string('review_frequency', 16)->nullable(); // monthly|quarterly|half_yearly|annually|custom
                $table->unsignedInteger('review_interval_days')->nullable();
                $table->date('next_review_date')->nullable()->index();
                $table->date('last_reviewed_at')->nullable();
                $table->json('tags')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('legal_document_versions')) {
            Schema::create('legal_document_versions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('document_id')->index();
                $table->unsignedInteger('version_major')->default(1);
                $table->unsignedInteger('version_minor')->default(0);
                $table->string('title');
                $table->longText('content_json')->nullable();  // tiptap document
                $table->longText('content_html')->nullable();  // sanitized render
                $table->text('change_summary')->nullable();
                $table->json('attachments')->nullable();       // [{id, name, url}]
                $table->string('status', 24)->default('draft')->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamps();
                $table->unique(['document_id', 'version_major', 'version_minor'], 'legal_versions_doc_ver_unique');
            });
        }

        if (! Schema::hasTable('legal_document_comments')) {
            Schema::create('legal_document_comments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('version_id')->index();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('parent_id')->nullable()->index();
                $table->text('body');
                $table->json('selection')->nullable(); // {from, to, quote}
                $table->string('status', 12)->default('open')->index();
                $table->unsignedBigInteger('resolved_by')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('legal_document_approvals')) {
            Schema::create('legal_document_approvals', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('version_id')->index();
                $table->unsignedBigInteger('approver_id')->index();
                $table->unsignedInteger('level')->default(1);
                $table->string('status', 12)->default('pending')->index();
                $table->text('comment')->nullable();
                $table->timestamp('requested_at')->nullable();
                $table->timestamp('acted_at')->nullable();
            });
        }

        if (! Schema::hasTable('legal_document_audits')) {
            Schema::create('legal_document_audits', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('document_id')->nullable();
                $table->unsignedBigInteger('version_id')->nullable();
                $table->string('event', 40); // created|updated|submitted|approved|...
                $table->unsignedBigInteger('user_id')->nullable();
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                $table->json('meta')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['document_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('legal_templates')) {
            Schema::create('legal_templates', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('name');
                $table->unsignedBigInteger('type_id')->nullable();
                $table->unsignedBigInteger('category_id')->nullable();
                $table->text('description')->nullable();
                $table->longText('content_json')->nullable();
                $table->longText('content_html')->nullable();
                $table->json('default_metadata')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('legal_settings')) {
            Schema::create('legal_settings', function (Blueprint $table) {
                $table->id();
                $table->string('numbering_format')->default('PAH-{TYPE}-{CAT}-{SEQ}');
                $table->unsignedInteger('review_reminder_days')->default(14);
                $table->string('export_footer')->nullable();
                $table->text('legal_disclaimer')->nullable();
                $table->json('settings')->nullable(); // future flags (workflow config etc.)
                $table->timestamps();
            });
            DB::table('legal_settings')->insert([
                'numbering_format' => 'PAH-{TYPE}-{CAT}-{SEQ}',
                'review_reminder_days' => 14,
                'export_footer' => 'PlantAtHome | Silvestrix Green LLP — Controlled Document',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach ([
            'legal_settings', 'legal_templates', 'legal_document_audits',
            'legal_document_approvals', 'legal_document_comments',
            'legal_document_versions', 'legal_documents',
            'legal_document_types', 'legal_categories',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
