<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nursery (vendor) context. Legacy `shops` are vendors; the v2 tables carry a
 * legacy_id so the backfill upsert is idempotent and the outbox projection can
 * write back to the legacy row. Money columns are decimals (never doubles).
 * Prefixed nursery_* (legacy marvel owns `shops`/`balances`/`withdraws`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nursery_nurseries')) {
            Schema::create('nursery_nurseries', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('legacy_id')->nullable()->unique();
                $table->uuid('owner_user_uuid')->nullable()->index();
                $table->string('name');
                $table->string('slug')->nullable()->unique();
                $table->text('description')->nullable();
                $table->json('logo')->nullable();
                $table->json('cover_image')->nullable();
                $table->json('address')->nullable();
                $table->string('status', 20)->default('pending')->index(); // pending | active | suspended | rejected
                $table->decimal('commission_rate', 5, 2)->nullable();
                $table->json('settings')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('nursery_documents')) {
            Schema::create('nursery_documents', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('nursery_id');
                $table->string('kind');                              // gstin | pan | license | ...
                $table->json('file')->nullable();
                $table->string('status', 20)->default('pending');    // pending | approved | rejected
                $table->text('note')->nullable();
                $table->uuid('reviewed_by_uuid')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();

                $table->foreign('nursery_id')->references('id')->on('nursery_nurseries')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('nursery_balances')) {
            Schema::create('nursery_balances', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('legacy_id')->nullable()->unique();
                $table->unsignedBigInteger('nursery_id')->unique();
                $table->decimal('total_earnings', 12, 2)->default(0);
                $table->decimal('withdrawn_amount', 12, 2)->default(0);
                $table->decimal('current_balance', 12, 2)->default(0);
                $table->json('payment_info')->nullable();
                $table->timestamps();

                $table->foreign('nursery_id')->references('id')->on('nursery_nurseries')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('nursery_ledger_entries')) {
            Schema::create('nursery_ledger_entries', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('nursery_id')->index();
                $table->string('type', 30);                          // sale_credit | commission_debit | withdrawal_debit | adjustment
                $table->decimal('amount', 12, 2);
                $table->string('reference_type')->nullable();
                $table->uuid('reference_uuid')->nullable();
                $table->string('note')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->foreign('nursery_id')->references('id')->on('nursery_nurseries');
            });
        }

        if (! Schema::hasTable('nursery_withdrawals')) {
            Schema::create('nursery_withdrawals', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('legacy_id')->nullable()->unique();
                $table->unsignedBigInteger('nursery_id')->index();
                $table->decimal('amount', 12, 2);
                // EXACT legacy status strings — the backfill passes them through.
                $table->string('status', 20)->default('pending')->index(); // pending | processing | approved | on_hold | rejected
                $table->string('payment_method')->nullable();
                $table->text('details')->nullable();
                $table->text('note')->nullable();
                $table->uuid('decided_by_uuid')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->string('idempotency_key')->nullable()->unique();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('nursery_id')->references('id')->on('nursery_nurseries');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nursery_withdrawals');
        Schema::dropIfExists('nursery_ledger_entries');
        Schema::dropIfExists('nursery_balances');
        Schema::dropIfExists('nursery_documents');
        Schema::dropIfExists('nursery_nurseries');
    }
};
