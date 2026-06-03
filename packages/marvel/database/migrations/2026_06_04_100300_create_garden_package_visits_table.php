<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('garden_package_visits')) {
            return;
        }
        Schema::create('garden_package_visits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('garden_package_id')->index();
            $table->date('scheduled_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('gardener_name')->nullable();
            $table->text('notes')->nullable();
            $table->string('status')->default('scheduled'); // scheduled/completed/missed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('garden_package_visits');
    }
};
