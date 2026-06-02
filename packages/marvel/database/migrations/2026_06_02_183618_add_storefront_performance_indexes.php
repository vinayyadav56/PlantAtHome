<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storefront performance indexes. The product list/filter query filters by
 * type_id + status + language; a composite index speeds it up considerably on
 * the 1.5k-row catalog. Each add is guarded so a pre-existing index (e.g. a
 * foreign-key index) doesn't fail the migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->safeIndex('products', ['type_id', 'status', 'language'], 'pah_products_type_status_lang');
        $this->safeIndex('products', ['status', 'language'], 'pah_products_status_lang');
        $this->safeIndex('products', ['shop_id'], 'pah_products_shop');
    }

    public function down(): void
    {
        foreach (['pah_products_type_status_lang', 'pah_products_status_lang', 'pah_products_shop'] as $name) {
            try {
                Schema::table('products', fn (Blueprint $t) => $t->dropIndex($name));
            } catch (\Throwable $e) {
                // index missing — ignore
            }
        }
    }

    private function safeIndex(string $table, array $columns, string $name): void
    {
        try {
            Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
        } catch (\Throwable $e) {
            // already exists — ignore
        }
    }
};
