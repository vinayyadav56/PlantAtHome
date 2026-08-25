<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DELETE /attachments/{id} sat on a customer route with no ownership check and
 * cascaded to a real S3 delete. Record the uploader so destroy() can require
 * owner-or-admin. Nullable — legacy rows have no known owner and stay
 * admin-deletable only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $t) {
            $t->unsignedBigInteger('user_id')->nullable()->after('url')->index();
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $t) {
            $t->dropIndex(['user_id']);
            $t->dropColumn('user_id');
        });
    }
};
