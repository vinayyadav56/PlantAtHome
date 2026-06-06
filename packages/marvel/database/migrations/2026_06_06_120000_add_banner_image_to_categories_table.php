<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('categories') && !Schema::hasColumn('categories', 'banner_image')) {
            Schema::table('categories', function (Blueprint $table) {
                // wide banner shown on the category's storefront page; JSON {id,original,thumbnail}
                $table->json('banner_image')->nullable()->after('image');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('categories') && Schema::hasColumn('categories', 'banner_image')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropColumn('banner_image');
            });
        }
    }
};
