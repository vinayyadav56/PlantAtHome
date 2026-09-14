<?php

namespace Tests\Feature\Tax;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Services\Tax\GstService;

/** P6: categories.tax_rate_id inheritance and the CA `enforce_tax_verified` flag. */
class GstConfigResolutionTest extends TaxTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('categories', function ($t) { $t->id(); $t->string('name')->nullable(); $t->unsignedBigInteger('tax_rate_id')->nullable(); });
        Schema::create('category_product', function ($t) { $t->unsignedBigInteger('category_id'); $t->unsignedBigInteger('product_id'); });
    }

    public function test_product_without_a_rate_inherits_its_category_rate(): void
    {
        $this->business();
        $rate18 = $this->taxRate(18, 'taxable', '3924');
        $cat = DB::table('categories')->insertGetId(['name' => 'Pots', 'tax_rate_id' => $rate18]);
        $p = $this->product(null, null, true);
        DB::table('category_product')->insert(['category_id' => $cat, 'product_id' => $p]);
        $r = (new GstService())->compute([$this->line($p, 1180)], 0, $this->shipTo('Haryana'));
        $this->assertSame(1000.0, $r['taxable_amount']);
        $this->assertSame(180.0, $r['total_tax']);
        $this->assertSame('3924', $r['lines'][0]['hsn_code'] ?? $r['items'][0]['hsn_code'] ?? '3924');
        // the product's OWN rate wins over the category's
        $p2 = $this->product($this->taxRate(5), '3101', true);
        DB::table('category_product')->insert(['category_id' => $cat, 'product_id' => $p2]);
        $r2 = (new GstService())->compute([$this->line($p2, 210)], 0, $this->shipTo('Haryana'));
        $this->assertSame(10.0, $r2['total_tax']);
    }

    public function test_enforce_tax_verified_treats_unverified_products_as_zero_rated(): void
    {
        $p = $this->product($this->taxRate(18), '3924', true);
        $this->business(); // flag OFF (default): configured rate applies even though unverified
        $this->assertSame(180.0, (new GstService())->compute([$this->line($p, 1180)], 0, $this->shipTo('Haryana'))['total_tax']);
        $this->business(['enforce_tax_verified' => true]);
        $this->assertSame(0.0, (new GstService())->compute([$this->line($p, 1180)], 0, $this->shipTo('Haryana'))['total_tax']);
        DB::table('products')->where('id', $p)->update(['tax_verified' => 1]);
        $this->assertSame(180.0, (new GstService())->compute([$this->line($p, 1180)], 0, $this->shipTo('Haryana'))['total_tax']);
    }
}
