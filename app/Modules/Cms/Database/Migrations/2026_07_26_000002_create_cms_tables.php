<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CMS (Section 3): pages + banners, optionally targeted to a city (null = global
 * default). Prefixed cms_* (legacy marvel owns `banners`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cms_pages')) {
            Schema::create('cms_pages', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->string('slug')->index();
                $table->string('title');
                $table->longText('body')->nullable();
                $table->uuid('city_uuid')->nullable()->index();  // null = global
                $table->string('status')->default('published');
                $table->uuid('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['slug', 'city_uuid']);
            });
        }

        if (! Schema::hasTable('cms_banners')) {
            Schema::create('cms_banners', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->uuid('uuid')->unique();
                $table->string('title')->nullable();
                $table->string('image_url');
                $table->string('link')->nullable();
                $table->string('position')->default('home');     // home | category | …
                $table->uuid('city_uuid')->nullable()->index();
                $table->unsignedInteger('sort')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['position', 'is_active']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_banners');
        Schema::dropIfExists('cms_pages');
    }
};
