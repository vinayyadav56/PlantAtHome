<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('voice_search_logs')) {
            return;
        }
        Schema::create('voice_search_logs', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->nullable()->index();
            $table->text('transcript');
            $table->text('search_text')->nullable();
            $table->string('category')->nullable();
            $table->integer('prompt_tokens')->default(0);
            $table->integer('completion_tokens')->default(0);
            $table->integer('total_tokens')->default(0);
            $table->decimal('cost_usd', 8, 6)->default(0);
            $table->decimal('cost_inr', 10, 2)->default(0);
            $table->timestamp('created_at')->useCurrent()->index('idx_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_search_logs');
    }
};
