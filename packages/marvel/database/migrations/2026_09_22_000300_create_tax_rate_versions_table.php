<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scheduled rate changes for a tax class.
 *
 * GST rates move on a date announced in advance, and until now the only way to
 * follow one was to edit the rate by hand on the morning it took effect — which
 * has to happen before the first order of the day, and silently reprices every
 * product pointing at that class the moment it is saved.
 *
 * A version says "this class is R% from D". The engine resolves the rate for
 * the order's date, so the change lands by itself; products keep pointing at the
 * same tax_classes row and never need re-pointing. Past orders are unaffected
 * either way — their rate is snapshotted on the line.
 *
 * tax_classes.rate stays the fallback for a class with no versions, which is
 * every class that exists today.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tax_rate_versions')) {
            return;
        }

        Schema::create('tax_rate_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tax_class_id')->index();
            $table->decimal('rate', 5, 2);
            $table->date('effective_from');
            // Null = open-ended, which is the normal case: the next version's
            // start closes the previous one.
            $table->date('effective_to')->nullable();
            $table->string('note')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            // One rate per class per start date — a second row for the same day
            // would make "which rate applies" a coin toss.
            $table->unique(['tax_class_id', 'effective_from'], 'tax_rate_versions_class_from_unique');
            $table->index(['tax_class_id', 'effective_from'], 'tax_rate_versions_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rate_versions');
    }
};
