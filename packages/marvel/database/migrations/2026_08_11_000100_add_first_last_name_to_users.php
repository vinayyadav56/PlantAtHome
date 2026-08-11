<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Split customer identity: users.name stays the authoritative display string
 * (every consumer — orders.customer_name snapshots, invoices, emails — keeps
 * reading it), while first_name/last_name become the structured source that
 * new registrations write. A User::saving observer keeps the two in sync in
 * both directions, so old clients sending only `name` still converge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 120)->nullable()->after('name');
            $table->string('last_name', 120)->nullable()->after('first_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name']);
        });
    }
};
