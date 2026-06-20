<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase B — Designations: a named permission TEMPLATE (job function) that
 * carries a default set of module permissions. Employees inherit a designation's
 * defaults; per-user overrides ({grant,revoke}) refine them. The resolved
 * "effective" set is materialised onto the user as direct Spatie permissions
 * (see PermissionResolver), so enforcement is unchanged.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('designations')) {
            return;
        }
        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('department')->nullable();
            $table->text('description')->nullable();
            // Array of module-permission strings (e.g. ["orders.view","orders.edit"]).
            $table->json('default_permissions')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('designations');
    }
};
