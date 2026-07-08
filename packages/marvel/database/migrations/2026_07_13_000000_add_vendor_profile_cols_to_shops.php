<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor profile fields on the `shops` table (the physical vendors table).
 * Additive + guarded. `approval_status` is an explicit lifecycle alongside the
 * legacy `is_active` go-live flag (approve sets approved + is_active=true).
 * created_by/updated_by are audit stamps.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('shops')) {
            return;
        }
        Schema::table('shops', function (Blueprint $table) {
            if (!Schema::hasColumn('shops', 'contact_person')) {
                $table->string('contact_person')->nullable()->after('name');
            }
            if (!Schema::hasColumn('shops', 'mobile')) {
                $table->string('mobile', 20)->nullable()->after('contact_person');
            }
            if (!Schema::hasColumn('shops', 'upi')) {
                $table->string('upi', 100)->nullable();
            }
            if (!Schema::hasColumn('shops', 'approval_status')) {
                // pending | approved | rejected
                $table->string('approval_status', 16)->default('pending')->index();
            }
            if (!Schema::hasColumn('shops', 'approved_at')) {
                $table->timestamp('approved_at')->nullable();
            }
            if (!Schema::hasColumn('shops', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->index();
            }
            if (!Schema::hasColumn('shops', 'updated_by')) {
                $table->unsignedBigInteger('updated_by')->nullable();
            }
        });

        // Backfill approval_status from the existing is_active flag so live vendors
        // aren't shown as "pending" (idempotent one-time sync of the new column).
        if (Schema::hasColumn('shops', 'approval_status')) {
            \Illuminate\Support\Facades\DB::table('shops')->where('is_active', true)->update(['approval_status' => 'approved']);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('shops')) {
            return;
        }
        Schema::table('shops', function (Blueprint $table) {
            foreach (['contact_person', 'mobile', 'upi', 'approval_status', 'approved_at', 'created_by', 'updated_by'] as $col) {
                if (Schema::hasColumn('shops', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
