<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make `refund_policies` mean something to the machine.
 *
 * The table has existed since 2023 as pure CMS content — title, slug, description, target,
 * status — rendered to shoppers and evaluated by nothing. "Returns within 7 days" lived only
 * in prose, so the only eligibility rule the system actually enforced was ReturnService's
 * hardcoded "the item must be delivered".
 *
 * These columns are the rules RefundPolicyService reads. Every one is nullable or zero-default
 * and means "no restriction", so an existing policy row keeps behaving exactly as it does
 * today until somebody fills them in.
 *
 * Deliberately NOT added: restocking_fee_percent and delivery_refundable. Both would have to
 * change what RefundService::slices() computes — tested money code — and nothing would read
 * them yet. A column nothing consumes is a promise the system does not keep.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('refund_policies')) {
            return;
        }
        Schema::table('refund_policies', function (Blueprint $table) {
            if (!Schema::hasColumn('refund_policies', 'return_window_days')) {
                // Days after DELIVERY a return/refund may still be requested. NULL = no limit.
                $table->unsignedSmallInteger('return_window_days')->nullable()->after('target');
            }
            if (!Schema::hasColumn('refund_policies', 'cancellation_window_hours')) {
                // Hours after the order is PLACED it may still be cancelled for a refund, used
                // for orders that have not shipped. NULL = no limit.
                $table->unsignedSmallInteger('cancellation_window_hours')->nullable()->after('return_window_days');
            }
            if (!Schema::hasColumn('refund_policies', 'auto_approve_under')) {
                // Refunds at or below this value skip manual review (PRD §42). NULL = always review.
                $table->decimal('auto_approve_under', 14, 2)->nullable()->after('cancellation_window_hours');
            }
            if (!Schema::hasColumn('refund_policies', 'excluded_category_ids')) {
                // Categories that are never refundable (live plants past a window, clearance…).
                $table->json('excluded_category_ids')->nullable()->after('auto_approve_under');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('refund_policies')) {
            return;
        }
        Schema::table('refund_policies', function (Blueprint $table) {
            foreach (['return_window_days', 'cancellation_window_hours', 'auto_approve_under', 'excluded_category_ids'] as $col) {
                if (Schema::hasColumn('refund_policies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
