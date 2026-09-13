<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Accounting P12 — reconciliation runs + findings (spec §37-40, §46). Additive. */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('acc_reconciliation_runs')) {
            Schema::create('acc_reconciliation_runs', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('kinds', 120);                 // csv of checks run
                $t->date('period_from')->nullable();
                $t->date('period_to')->nullable();
                $t->string('status', 12)->default('running'); // running|clean|findings|error
                $t->unsignedInteger('findings_count')->default(0);
                $t->json('summary')->nullable();
                $t->string('ran_by', 64)->nullable();
                $t->timestamp('started_at')->nullable();
                $t->timestamp('finished_at')->nullable();
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('acc_reconciliation_findings')) {
            Schema::create('acc_reconciliation_findings', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('run_id')->index();
                $t->string('kind', 16);                   // journal|vendor|payment|gst|legacy|inventory
                $t->string('subject_type', 32);           // shop|order|account|journal_entry|tax_kind|period
                $t->string('subject_id', 64);
                $t->decimal('expected', 14, 2)->nullable();
                $t->decimal('actual', 14, 2)->nullable();
                $t->decimal('difference', 14, 2)->nullable();
                $t->string('message', 500);
                $t->string('status', 12)->default('open'); // open|explained|resolved
                $t->text('note')->nullable();
                $t->string('resolved_by', 64)->nullable();
                $t->timestamp('resolved_at')->nullable();
                $t->timestamps();
                $t->index(['kind', 'status']);
                $t->index(['subject_type', 'subject_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_reconciliation_findings');
        Schema::dropIfExists('acc_reconciliation_runs');
    }
};
