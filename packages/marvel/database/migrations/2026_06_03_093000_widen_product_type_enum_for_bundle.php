<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Enums\ProductType;

/**
 * The products.product_type column is a DB enum created with only
 * ('simple','variable'). ProductType::BUNDLE was added in PHP but the column
 * was never widened, so MySQL silently truncates 'bundle' → '' on insert,
 * breaking bundle products. Widen the enum to every ProductType value.
 * (MySQL-only ALTER; guarded.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql' || !Schema::hasColumn('products', 'product_type')) {
            return;
        }
        $values = array_values(ProductType::getValues()); // simple, variable, bundle
        $list = implode(',', array_map(fn ($v) => "'" . addslashes($v) . "'", $values));
        DB::statement("ALTER TABLE products MODIFY COLUMN product_type ENUM($list) NOT NULL DEFAULT 'simple'");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql' || !Schema::hasColumn('products', 'product_type')) {
            return;
        }
        DB::statement("ALTER TABLE products MODIFY COLUMN product_type ENUM('simple','variable') NOT NULL DEFAULT 'simple'");
    }
};
