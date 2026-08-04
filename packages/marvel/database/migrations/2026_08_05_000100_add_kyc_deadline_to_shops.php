<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KYC deadline on `shops`.
 *
 * Vendors can register without their documents so onboarding is never blocked
 * (the API has always allowed this — see ShopCreateRequest). What was missing
 * was a clock: `documents_due_at` is when the missing documents stop being
 * acceptable, after which a nightly sweep moves the vendor to `on_hold`.
 *
 * `approval_status` needs no change — it is a plain varchar with no enum or
 * check constraint, so `on_hold` joins pending/approved/rejected for free.
 *
 * Note `documents_due_at` is deliberately NOT added to ShopRepository::$dataArray:
 * that whitelist is what makes a column client-writable, and the deadline must
 * only ever move via the extend endpoint — the same protection `approval_status`
 * already relies on.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('shops')) {
            return;
        }
        Schema::table('shops', function (Blueprint $table) {
            if (!Schema::hasColumn('shops', 'documents_due_at')) {
                // Indexed: the nightly sweep queries exactly this column.
                $table->timestamp('documents_due_at')->nullable()->index();
            }
            if (!Schema::hasColumn('shops', 'hold_reason')) {
                $table->string('hold_reason', 255)->nullable();
            }
            if (!Schema::hasColumn('shops', 'kyc_reminded_at')) {
                // Last warning email, so the sweep never mails the same vendor twice.
                $table->timestamp('kyc_reminded_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('shops')) {
            return;
        }
        Schema::table('shops', function (Blueprint $table) {
            foreach (['documents_due_at', 'hold_reason', 'kyc_reminded_at'] as $col) {
                if (Schema::hasColumn('shops', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
