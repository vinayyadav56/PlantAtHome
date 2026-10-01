<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the PSP says about a refund we already paid out, as distinct from what WE did.
 *
 * `refunded_at` records the moment our payout ran. It says nothing about whether the gateway
 * actually moved the money — Razorpay settles a refund asynchronously and can report
 * `refund.failed` minutes later. Until now there was nowhere to put that answer, so a refund
 * the gateway had rejected was indistinguishable from one it had completed.
 *
 * Deliberately NOT reusing `refunds.status`: that column is our own approval lifecycle
 * (pending → approved/rejected) and an operator acts on it. Gateway state is a separate axis;
 * collapsing the two would make "approved" ambiguous.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('refunds')) {
            return;
        }
        Schema::table('refunds', function (Blueprint $table) {
            if (!Schema::hasColumn('refunds', 'gateway_status')) {
                // processed | failed — mirrors the PSP's own vocabulary, null until it tells us.
                $table->string('gateway_status', 32)->nullable()->after('gateway_refund_id');
            }
            if (!Schema::hasColumn('refunds', 'failure_reason')) {
                $table->text('failure_reason')->nullable()->after('gateway_status');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('refunds')) {
            return;
        }
        Schema::table('refunds', function (Blueprint $table) {
            foreach (['gateway_status', 'failure_reason'] as $col) {
                if (Schema::hasColumn('refunds', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
