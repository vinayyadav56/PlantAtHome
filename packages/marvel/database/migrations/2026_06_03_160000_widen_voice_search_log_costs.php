<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * gpt-4o-mini costs ~₹0.003 per voice query, which rounded to ₹0.00 under the
 * original decimal(10,2)/decimal(8,6) columns — making the admin cost dashboard
 * read zero. Widen the precision so sub-paise costs persist.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('voice_search_logs')) {
            return;
        }
        Schema::table('voice_search_logs', function (Blueprint $table) {
            $table->decimal('cost_usd', 12, 8)->default(0)->change();
            $table->decimal('cost_inr', 12, 6)->default(0)->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('voice_search_logs')) {
            return;
        }
        Schema::table('voice_search_logs', function (Blueprint $table) {
            $table->decimal('cost_usd', 8, 6)->default(0)->change();
            $table->decimal('cost_inr', 10, 2)->default(0)->change();
        });
    }
};
