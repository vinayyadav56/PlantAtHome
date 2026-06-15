<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('care_reminders')) {
            return;
        }
        Schema::create('care_reminders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('care_plan_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('type'); // watering | misting | fertilize | rotate | repot | prune | health_check | sunlight
            $table->string('title');
            $table->text('note')->nullable();
            $table->integer('interval_days')->default(7);
            $table->timestamp('next_due_at')->nullable()->index();
            $table->timestamp('last_done_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('care_reminders');
    }
};
