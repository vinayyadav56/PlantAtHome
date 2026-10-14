<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Product attributes for every vertical (Gardening Tools brief 2026-10-14), by extending the
 * existing plant_attribute_* layer instead of building a second one.
 *
 *   definitions: + code (stable, immutable), unit, numeric limits, text length, vertical
 *                (type_id), variant-axis flag + link (Phase 5), help text
 *   scopes:      NEW plant_attribute_scopes — a definition applies to these categories and
 *                everything beneath them; per-scope "required"
 *   values:      + value_number / value_number_max for number, range and boolean values
 *
 * The two existing definitions (Suitable Spaces, Special Characteristics) describe plants:
 * they are scoped to the Plants vertical, so nothing about the plant filters changes.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('plant_attribute_definitions')) {
            return;
        }

        $add = function (string $table, string $column, callable $define) {
            if (!Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $t) => $define($t));
            }
        };
        $add('plant_attribute_definitions', 'code', fn ($t) => $t->string('code', 64)->nullable()->after('slug'));
        $add('plant_attribute_definitions', 'unit', fn ($t) => $t->string('unit', 16)->nullable()->after('type'));
        $add('plant_attribute_definitions', 'min_value', fn ($t) => $t->decimal('min_value', 14, 4)->nullable()->after('unit'));
        $add('plant_attribute_definitions', 'max_value', fn ($t) => $t->decimal('max_value', 14, 4)->nullable()->after('min_value'));
        $add('plant_attribute_definitions', 'step', fn ($t) => $t->decimal('step', 14, 4)->nullable()->after('max_value'));
        $add('plant_attribute_definitions', 'max_length', fn ($t) => $t->unsignedInteger('max_length')->nullable()->after('step'));
        $add('plant_attribute_definitions', 'type_id', fn ($t) => $t->unsignedBigInteger('type_id')->nullable()->index()->after('max_length'));
        $add('plant_attribute_definitions', 'is_variant_axis', fn ($t) => $t->boolean('is_variant_axis')->default(false)->after('is_facet'));
        $add('plant_attribute_definitions', 'attribute_id', fn ($t) => $t->unsignedBigInteger('attribute_id')->nullable()->after('is_variant_axis'));
        $add('plant_attribute_definitions', 'help_text', fn ($t) => $t->string('help_text')->nullable()->after('attribute_id'));

        // The stable code starts as the slug (both are unique and never rewritten).
        DB::table('plant_attribute_definitions')->whereNull('code')->update(['code' => DB::raw('slug')]);
        try {
            Schema::table('plant_attribute_definitions', fn (Blueprint $t) => $t->unique('code', 'pad_code_unique'));
        } catch (\Throwable $e) {
            Log::warning('plant_attribute_definitions code index not added: ' . $e->getMessage());
        }

        // What exists today describes plants: keep it on Plants.
        $plants = DB::table('types')->where('slug', 'plants')->orderBy('id')->value('id');
        if ($plants) {
            DB::table('plant_attribute_definitions')->whereNull('type_id')->update(['type_id' => $plants]);
        }

        if (!Schema::hasTable('plant_attribute_scopes')) {
            Schema::create('plant_attribute_scopes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('definition_id');
                $t->unsignedBigInteger('category_id')->index();
                $t->boolean('is_required')->default(false);
                $t->integer('sort')->default(0);
                $t->timestamps();
                $t->unique(['definition_id', 'category_id']);
            });
        }

        if (Schema::hasTable('plant_attribute_product')) {
            $add('plant_attribute_product', 'value_number', fn ($t) => $t->decimal('value_number', 14, 4)->nullable()->after('value_text'));
            $add('plant_attribute_product', 'value_number_max', fn ($t) => $t->decimal('value_number_max', 14, 4)->nullable()->after('value_number'));
            try {
                Schema::table('plant_attribute_product', fn (Blueprint $t) => $t->index(['definition_id', 'value_number'], 'pap_def_number_index'));
            } catch (\Throwable $e) {
                Log::warning('plant_attribute_product number index not added: ' . $e->getMessage());
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('plant_attribute_scopes');
        foreach ([
            'plant_attribute_product'     => ['value_number', 'value_number_max'],
            'plant_attribute_definitions' => ['code', 'unit', 'min_value', 'max_value', 'step', 'max_length', 'type_id', 'is_variant_axis', 'attribute_id', 'help_text'],
        ] as $table => $columns) {
            try {
                if ($table === 'plant_attribute_product') {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex('pap_def_number_index'));
                } else {
                    Schema::table($table, fn (Blueprint $t) => $t->dropUnique('pad_code_unique'));
                }
            } catch (\Throwable $e) {
                // never added
            }
            $present = array_values(array_filter($columns, fn ($c) => Schema::hasColumn($table, $c)));
            if ($present) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($present));
            }
        }
    }
};
