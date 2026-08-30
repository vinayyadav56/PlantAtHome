<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — operations registers that sit beside the document library:
 * risks, compliance requirements, and the corrective actions either can raise.
 * Corrective actions attach polymorphically so a finding from an audit, a risk
 * or a document review all land in one queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('legal_risk_items')) {
            Schema::create('legal_risk_items', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('risk_code', 32)->unique();   // RSK-001
                $table->string('title');
                $table->string('category', 40)->index();
                $table->text('description')->nullable();
                // 1-5 scales; the score formula itself is configurable in
                // legal_settings so the model can change without a migration.
                $table->unsignedTinyInteger('probability')->default(3);
                $table->unsignedTinyInteger('impact')->default(3);
                $table->unsignedSmallInteger('risk_score')->default(9)->index();
                $table->string('risk_level', 12)->default('medium')->index();
                $table->unsignedBigInteger('owner_id')->nullable()->index();
                $table->string('department', 64)->nullable();
                $table->text('mitigation_plan')->nullable();
                $table->text('contingency_plan')->nullable();
                $table->string('status', 20)->default('open')->index(); // open|mitigating|accepted|closed
                $table->date('review_date')->nullable()->index();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('legal_compliance_items')) {
            Schema::create('legal_compliance_items', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('item_code', 32)->unique();   // CMP-001
                $table->string('title');
                $table->string('compliance_area', 120)->index();
                $table->text('requirement')->nullable();
                $table->text('evidence')->nullable();
                $table->string('applicable_department', 64)->nullable();
                $table->unsignedBigInteger('owner_id')->nullable()->index();
                $table->string('status', 24)->default('under_review')->index();
                $table->string('review_frequency', 16)->nullable();
                $table->date('last_review_date')->nullable();
                $table->date('next_review_date')->nullable()->index();
                $table->unsignedBigInteger('document_id')->nullable()->index(); // the governing policy
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('legal_corrective_actions')) {
            Schema::create('legal_corrective_actions', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                // risk | compliance | document — one queue, many sources.
                $table->string('source_type', 24)->index();
                $table->unsignedBigInteger('source_id')->index();
                $table->string('title');
                $table->text('description')->nullable();
                $table->string('priority', 12)->default('medium')->index(); // low|medium|high|critical
                $table->unsignedBigInteger('owner_id')->nullable()->index();
                $table->date('due_date')->nullable()->index();
                $table->string('status', 16)->default('open')->index();     // open|in_progress|done|cancelled
                $table->timestamp('completed_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['legal_corrective_actions', 'legal_compliance_items', 'legal_risk_items'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
