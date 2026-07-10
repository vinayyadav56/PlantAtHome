<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Analytics read model (Section 3): event-fed rollup counters. Each row is a
 * (metric, dimension) accumulator — e.g. ('revenue','global'), ('orders',
 * 'global'), ('revenue','nursery:<uuid>'). KPIs (AOV, per-vendor) are derived on
 * read. Populated by subscribers off the request path. Prefixed analytics_*.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('analytics_counters')) {
            return;
        }

        Schema::create('analytics_counters', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('metric');                            // revenue | orders | …
            $table->string('dimension')->default('global');      // global | nursery:<uuid> | …
            $table->decimal('value_sum', 16, 2)->default(0);
            $table->unsignedBigInteger('value_count')->default(0);
            $table->timestamps();

            $table->unique(['metric', 'dimension']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_counters');
    }
};
