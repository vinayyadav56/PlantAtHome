<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('garden_leads')) {
            return;
        }
        Schema::create('garden_leads', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('city')->nullable();
            $table->string('garden_type')->nullable(); // balcony/terrace/backyard/indoor/rooftop
            $table->string('space_size')->nullable();
            $table->string('budget_range')->nullable();
            $table->text('message')->nullable();
            $table->string('status')->default('new')->index(); // new/contacted/quoted/converted/closed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('garden_leads');
    }
};
