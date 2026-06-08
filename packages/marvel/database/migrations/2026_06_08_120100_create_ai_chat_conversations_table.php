<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('ai_chat_conversations')) {
            return;
        }
        Schema::create('ai_chat_conversations', function (Blueprint $table) {
            $table->id();
            // Service-generated uuid. Unique so the persist callback is an
            // idempotent upsert (end + idle-sweep can both fire safely).
            $table->uuid('conversation_id')->unique();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('plant_id')->nullable()->index();
            $table->string('plant_name')->nullable();
            $table->string('language', 12)->nullable();
            $table->unsignedSmallInteger('prompt_count')->default(0);
            // Full ordered transcript: [{role,content,ts}, ...].
            $table->json('transcript')->nullable();
            $table->integer('prompt_tokens')->default(0);
            $table->integer('completion_tokens')->default(0);
            $table->integer('total_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->decimal('cost_inr', 10, 4)->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('created_at')->useCurrent()->index('idx_aic_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_conversations');
    }
};
