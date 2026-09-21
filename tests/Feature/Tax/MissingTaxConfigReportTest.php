<?php

declare(strict_types=1);

namespace Tests\Feature\Tax;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Http\Controllers\ProductController;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Finance -> Missing Tax Config.
 *
 * The engine never invents a rate, so an unconfigured product simply sells at
 * 0% — correct, and invisible. This is the worklist that makes it visible, and
 * it has to agree exactly with what the engine would do: a product with no rate
 * of its own but a rate on its category IS configured and must not appear, or
 * the CA chases rows that are already fine and stops trusting the list.
 */
final class MissingTaxConfigReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default'            => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');

        Schema::create('products', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('hsn_code', 16)->nullable();
            $t->unsignedBigInteger('tax_rate_id')->nullable();
            $t->boolean('tax_verified')->default(false);
            $t->softDeletes();
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('tax_rate_id')->nullable();
        });
        Schema::create('category_product', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('category_id');
        });
    }

    /** @return string[] names of the products the report lists */
    private function report(): array
    {
        $controller = app(ProductController::class);
        $method = new ReflectionMethod($controller, 'applyMissingTaxConfig');
        $method->setAccessible(true);

        $query = $method->invoke(
            $controller,
            DB::table('products'),
            Request::create('/', 'GET', ['missing_tax_config' => 1])
        );

        return $query->orderBy('id')->pluck('name')->all();
    }

    private function product(string $name, ?int $rateId, ?string $hsn, bool $verified): int
    {
        return DB::table('products')->insertGetId([
            'name' => $name, 'tax_rate_id' => $rateId, 'hsn_code' => $hsn, 'tax_verified' => $verified,
        ]);
    }

    public function test_it_lists_a_product_with_no_rate_at_all(): void
    {
        $this->product('Unconfigured pot', null, null, false);

        $this->assertSame(['Unconfigured pot'], $this->report());
    }

    public function test_it_leaves_a_fully_configured_product_alone(): void
    {
        $this->product('Ceramic pot', 3, '6912', true);

        $this->assertSame([], $this->report());
    }

    public function test_a_rate_inherited_from_a_category_counts_as_configured(): void
    {
        // The engine falls back to the category, so listing these would send the
        // CA after rows that are already correct.
        $id = $this->product('Inherits its category', null, '0602', true);
        DB::table('categories')->insert(['id' => 5, 'tax_rate_id' => 9]);
        DB::table('category_product')->insert(['product_id' => $id, 'category_id' => 5]);

        $this->assertSame([], $this->report());
    }

    public function test_a_category_without_a_rate_does_not_rescue_a_product(): void
    {
        $id = $this->product('Category has no rate either', null, '0602', true);
        DB::table('categories')->insert(['id' => 6, 'tax_rate_id' => null]);
        DB::table('category_product')->insert(['product_id' => $id, 'category_id' => 6]);

        $this->assertSame(['Category has no rate either'], $this->report());
    }

    public function test_a_missing_hsn_is_listed_even_when_the_rate_is_right(): void
    {
        // The rate decides what is collected; the HSN is what the return needs.
        $this->product('Rate but no HSN', 3, null, true);
        $this->product('Rate but blank HSN', 3, '', true);

        $this->assertSame(['Rate but no HSN', 'Rate but blank HSN'], $this->report());
    }

    public function test_an_unverified_product_is_listed(): void
    {
        $this->product('Awaiting the CA', 3, '6912', false);

        $this->assertSame(['Awaiting the CA'], $this->report());
    }

    public function test_without_the_flag_the_list_is_untouched(): void
    {
        $this->product('Unconfigured pot', null, null, false);

        $controller = app(ProductController::class);
        $method = new ReflectionMethod($controller, 'applyMissingTaxConfig');
        $method->setAccessible(true);
        $query = $method->invoke($controller, DB::table('products'), Request::create('/', 'GET'));

        $this->assertCount(1, $query->get(), 'the param is opt-in');
    }
}
