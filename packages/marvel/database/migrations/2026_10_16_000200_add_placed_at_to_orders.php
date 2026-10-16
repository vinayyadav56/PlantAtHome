<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When an order was PLACED — announced to the customer, the owner and vendors, and shown in the
 * order lists. An online-payment checkout creates its order row before the customer pays (Razorpay
 * needs it), so that row is only a payment attempt until the payment succeeds or the customer
 * switches it to Cash on Delivery (owner rule, 2026-10-16). NULL = an unplaced attempt.
 *
 * Every existing order counts as placed: they were all announced when they were created. The
 * column defaults to now on MySQL, so any insert path that doesn't name it (old code during the
 * deploy gap, other tools) is "placed" too; only the storefront checkout writes NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders') || Schema::hasColumn('orders', 'placed_at')) {
            return;
        }
        Schema::table('orders', function (Blueprint $table) {
            $column = $table->timestamp('placed_at')->nullable();
            // SQLite cannot ALTER-add a non-constant default (tests build their own schema).
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $column->useCurrent();
            }
        });
        DB::table('orders')->update(['placed_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'placed_at')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('placed_at');
            });
        }
    }
};
