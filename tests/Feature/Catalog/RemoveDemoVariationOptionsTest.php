<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 2026_10_08_000200_remove_demo_variation_options, on the production shape: real plants
 * carrying Pickbazar demo variants ("Picture Book/French ₹140") beside their real sizes.
 */
final class RemoveDemoVariationOptionsTest extends TestCase
{
    private const MIGRATION = 'packages/marvel/database/migrations/2026_10_08_000200_remove_demo_variation_options.php';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default'            => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        ]);
        DB::purge('sqlite');

        Schema::create('types', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('slug');
        });
        Schema::create('products', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('slug');
            $t->unsignedBigInteger('type_id');
            $t->decimal('min_price')->nullable();
            $t->decimal('max_price')->nullable();
            $t->integer('quantity')->default(0);
            $t->boolean('in_stock')->default(true);
            $t->timestamps();
        });
        Schema::create('variation_options', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('title');
            $t->string('price');
            $t->string('sale_price')->nullable();
            $t->unsignedBigInteger('quantity')->default(10);
            $t->json('options');
            $t->unsignedBigInteger('product_id');
            $t->timestamps();
        });
        foreach (['order_items', 'carts', 'wishlists'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('product_id')->nullable();
                $t->unsignedBigInteger('variation_option_id')->nullable();
            });
        }

        DB::table('types')->insert([['id' => 1, 'slug' => 'plants'], ['id' => 2, 'slug' => 'tools']]);
        DB::table('products')->insert([
            ['id' => 1, 'slug' => 'vinca', 'type_id' => 1, 'min_price' => 80, 'max_price' => 779, 'quantity' => 60],
            ['id' => 2, 'slug' => 'demo-only', 'type_id' => 1, 'min_price' => 140, 'max_price' => 140, 'quantity' => 10],
            ['id' => 3, 'slug' => 'trowel', 'type_id' => 2, 'min_price' => 199, 'max_price' => 199, 'quantity' => 10],
            ['id' => 4, 'slug' => 'salvia', 'type_id' => 1, 'min_price' => 220, 'max_price' => 819, 'quantity' => 30],
        ]);
        $size = fn (string $v) => json_encode([['name' => 'Size', 'value' => $v]]);
        $demo = fn (string $a, string $b) => json_encode([['name' => 'Book Type', 'value' => $a], ['name' => 'Language', 'value' => $b]]);
        DB::table('variation_options')->insert([
            ['id' => 10, 'title' => 'Picture Book/French', 'price' => '80', 'options' => $demo('Picture Book', 'French'), 'product_id' => 1],
            ['id' => 11, 'title' => 'Paperback Book/Hindi', 'price' => '140', 'options' => $demo('Paperback Book', 'Hindi'), 'product_id' => 1],
            ['id' => 15, 'title' => 'Blue', 'price' => '120', 'options' => json_encode([['name' => 'Color', 'value' => 'Blue']]), 'product_id' => 1],
            ['id' => 12, 'title' => 'Small', 'price' => '299', 'options' => $size('Small'), 'product_id' => 1],
            ['id' => 13, 'title' => 'Medium', 'price' => '509', 'options' => $size('Medium'), 'product_id' => 1],
            ['id' => 14, 'title' => 'Large', 'price' => '779', 'options' => $size('Large'), 'product_id' => 1],
            ['id' => 20, 'title' => 'Picture Book/English', 'price' => '140', 'options' => $demo('Picture Book', 'English'), 'product_id' => 2],
            ['id' => 30, 'title' => 'Picture Book/English', 'price' => '199', 'options' => $demo('Picture Book', 'English'), 'product_id' => 3],
            ['id' => 40, 'title' => 'Magni/Hebrew', 'price' => '220', 'options' => json_encode([['name' => 'Aurora Pope', 'value' => 'Magni'], ['name' => 'Language', 'value' => 'Hebrew']]), 'product_id' => 4],
            ['id' => 41, 'title' => 'Small', 'price' => '309', 'options' => $size('Small'), 'product_id' => 4],
            ['id' => 42, 'title' => 'Large', 'price' => '819', 'options' => $size('Large'), 'product_id' => 4],
        ]);
        DB::table('order_items')->insert(['product_id' => 4, 'variation_option_id' => 40]); // salvia's demo row was ordered
        DB::table('carts')->insert(['product_id' => 1, 'variation_option_id' => 11]);
        DB::table('wishlists')->insert(['product_id' => 1, 'variation_option_id' => 10]);
    }

    private function ids(): array
    {
        return DB::table('variation_options')->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    public function test_up_removes_only_unreferenced_demo_rows_of_plants_that_keep_their_sizes_and_down_restores(): void
    {
        $migration = require base_path(self::MIGRATION);
        ob_start();
        $migration->up();
        $out = (string) ob_get_clean();

        // Vinca's three demo rows (Book Type/Language and Color) gone; demo-only plant, the tool,
        // and the ORDERED salvia row kept.
        $this->assertSame([12, 13, 14, 20, 30, 40, 41, 42], $this->ids());
        $this->assertStringContainsString('variation_option 40: referenced by order_items', $out);
        // Vinca's "from" price no longer comes from the ₹80 demo row.
        $vinca = DB::table('products')->find(1);
        $this->assertEquals(299, (float) $vinca->min_price);
        $this->assertEquals(779, (float) $vinca->max_price);
        $this->assertSame(30, (int) $vinca->quantity, 'quantity = sum of the surviving size rows (3 × 10)');
        // Copies that pointed at removed rows are gone.
        $this->assertSame(0, DB::table('carts')->count());
        $this->assertSame(0, DB::table('wishlists')->count());
        $this->assertEquals(220, (float) DB::table('products')->find(4)->min_price, 'salvia untouched (its demo row was kept)');

        $migration->down();

        $this->assertSame([10, 11, 12, 13, 14, 15, 20, 30, 40, 41, 42], $this->ids());
        $this->assertEquals(80, (float) DB::table('products')->find(1)->min_price);
        $this->assertSame(60, (int) DB::table('products')->find(1)->quantity);
        $this->assertSame(1, DB::table('carts')->count());
        $this->assertSame(1, DB::table('wishlists')->count());
        $this->assertFalse(Schema::hasTable('pah_demo_variant_backup'));
    }

    public function test_no_plants_type_is_a_no_op(): void
    {
        DB::table('types')->where('slug', 'plants')->delete();
        (require base_path(self::MIGRATION))->up();

        $this->assertCount(11, $this->ids());
        $this->assertFalse(Schema::hasTable('pah_demo_variant_backup'));
    }
}
