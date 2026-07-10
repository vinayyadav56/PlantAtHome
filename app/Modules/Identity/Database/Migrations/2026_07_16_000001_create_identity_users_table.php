<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identity users. Each user has exactly one role and an optional nursery scope
 * (nursery_owner / nursery_staff are bound to a single nursery; customer/admin/
 * super_admin have none). nursery_id is an opaque UUID scope key — the Nursery
 * context that owns nurseries lands in a later phase; Identity only enforces the
 * scope, it does not FK into another module's table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('identity_users')) {
            return;
        }

        Schema::create('identity_users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('role_id');
            $table->uuid('nursery_id')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->foreign('role_id')->references('id')->on('identity_roles');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_users');
    }
};
