<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a proposed product was rejected.
 *
 * A vendor may PROPOSE a plant the catalogue is missing; it enters `under_review` and an
 * admin decides. Until now the decision was a status change and nothing else — the vendor
 * saw "rejected" with no way to learn what to fix, and re-submitted the same thing. The
 * inventory-review pipeline solved this long ago with `review_comment`; product proposals
 * simply never got the same column.
 *
 * Deliberately NOT a new status value: `products.status` is a MySQL enum materialised from
 * ProductStatus::getValues() at migration time, so extending that vocabulary means altering
 * the column. The existing under_review / rejected / publish / draft / unpublish covers the
 * states; only the REASON was missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products') || Schema::hasColumn('products', 'review_note')) {
            return;
        }
        Schema::table('products', function (Blueprint $table) {
            $table->text('review_note')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('products') || !Schema::hasColumn('products', 'review_note')) {
            return;
        }
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('review_note'));
    }
};
