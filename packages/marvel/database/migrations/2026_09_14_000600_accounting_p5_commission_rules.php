<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Accounting P5 — vendor commission rules (spec §12-13). The applied rule is snapshotted per line. */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('vendor_commission_rules')) {
            Schema::create('vendor_commission_rules', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('scope', 12);                                   // vendor | category | product
                $t->unsignedBigInteger('shop_id')->nullable()->index();    // vendor scope, or restrict a category/product rule to one vendor
                $t->unsignedBigInteger('category_id')->nullable()->index();
                $t->unsignedBigInteger('product_id')->nullable()->index();
                $t->string('mode', 20);                                    // percentage | fixed_per_item | fixed_per_order | cost_sheet
                $t->decimal('value', 14, 4)->default(0);                   // % for percentage, ₹ for fixed modes, ignored for cost_sheet
                $t->integer('priority')->default(0);                       // higher wins within a scope
                $t->date('effective_from')->nullable();
                $t->date('effective_to')->nullable();
                $t->boolean('is_active')->default(true);
                $t->string('note', 500)->nullable();
                $t->string('created_by', 64)->nullable();
                $t->timestamps();
                $t->index(['scope', 'is_active']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_commission_rules');
    }
};
