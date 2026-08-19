<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test-data management: an append-only record of every cleanup run, carrying the JSON
 * snapshot of everything it deleted (so a run is restorable), and an advisory registry
 * of records explicitly marked as test data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_data_cleanup_runs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('module', 40)->index();
            $t->string('mode', 20)->default('preview');       // preview | execute | restore
            $t->string('environment', 20)->nullable();        // staging | production (resolved, not APP_ENV)
            $t->json('scope')->nullable();                    // the exact filter the operator chose
            $t->json('table_counts')->nullable();             // per-table rows affected
            $t->unsignedInteger('total_rows')->default(0);
            /**
             * Every deleted row, keyed by table. This is what makes a hard delete reversible —
             * without it a mis-scoped run would be unrecoverable. Never written for previews.
             */
            $t->longText('snapshot')->nullable();
            $t->string('backup_reference')->nullable();        // prod: the mysqldump this ran after
            $t->unsignedBigInteger('actor_user_id')->nullable();
            $t->string('actor_name')->nullable();
            $t->string('ip', 64)->nullable();
            $t->string('status', 20)->default('pending');      // pending | completed | failed | restored
            $t->text('error')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });

        Schema::create('test_data_marks', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('markable_type', 120);
            $t->unsignedBigInteger('markable_id');
            $t->string('reason')->nullable();
            $t->unsignedBigInteger('marked_by')->nullable();
            $t->timestamps();
            $t->unique(['markable_type', 'markable_id'], 'test_data_marks_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_data_marks');
        Schema::dropIfExists('test_data_cleanup_runs');
    }
};
