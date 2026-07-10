<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The data-driven Rules Engine (Section 6). A rule is DATA: a scope, a priority,
 * a combinator over conditions (fact/operator/value), and actions (type/params).
 * Adding a rule = inserting rows via admin, zero deploys. Nothing is hardcoded
 * in PHP. Prefixed rule_* alongside the legacy marvel schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rule_definitions')) {
            Schema::create('rule_definitions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->string('name');
                $table->string('scope')->index();               // compatibility|visibility|pricing|…
                $table->integer('priority')->default(0);         // lower = evaluated first
                $table->string('condition_combinator')->default('all'); // all | any
                $table->boolean('is_active')->default(true);
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['scope', 'is_active', 'priority']);
            });
        }

        if (! Schema::hasTable('rule_conditions')) {
            Schema::create('rule_conditions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('rule_id');
                $table->string('fact');                          // e.g. variant.size, plant.is_fragile
                $table->string('operator');                      // eq|neq|in|not_in|gt|gte|lt|lte|exists|contains|not_contains
                $table->json('value')->nullable();               // scalar or array, typed by operator
                $table->timestamps();

                $table->foreign('rule_id')->references('id')->on('rule_definitions')->cascadeOnDelete();
                $table->index('rule_id');
            });
        }

        if (! Schema::hasTable('rule_actions')) {
            Schema::create('rule_actions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('rule_id');
                $table->string('type');                          // hide_option|require_option|block_selection|apply_discount|…
                $table->json('params')->nullable();
                $table->unsignedInteger('sort')->default(0);
                $table->timestamps();

                $table->foreign('rule_id')->references('id')->on('rule_definitions')->cascadeOnDelete();
                $table->index('rule_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_actions');
        Schema::dropIfExists('rule_conditions');
        Schema::dropIfExists('rule_definitions');
    }
};
