<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Text specifications ("Safety instructions", "Care instructions") are longer than the
 * VARCHAR(191) value_text was created as. Production's MySQL runs non-strict, so a longer value
 * was cut to 191 characters with only a warning. TEXT holds the 5,000-character limit an admin can
 * set on a text attribute. value_text is in no index, so the change is a plain column rewrite.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('plant_attribute_product', 'value_text')
            || Schema::getColumnType('plant_attribute_product', 'value_text') === 'text') {
            return;
        }
        Schema::table('plant_attribute_product', fn (Blueprint $t) => $t->text('value_text')->nullable()->change());
    }

    public function down(): void
    {
        // Narrowing back would cut stored text: the column stays TEXT.
    }
};
