<?php

declare(strict_types=1);

namespace Tests\Feature\Tax;

use Illuminate\Support\Facades\DB;
use Marvel\Console\ApplyProdSizePricingCommand;
use Marvel\Console\ApplySizePricingCommand;

/**
 * The Size attribute is the variant master — delivery is charged off its rows —
 * so the writers that build size variations have to read their ladder from it
 * rather than from their own ['Small', 'Medium', 'Large'] literal. A size renamed
 * or added in admin used to leave those writers building variations the
 * storefront no longer offers, and a variation the writers did not know about
 * carries no delivery charge at all.
 */
final class SizeLadderTest extends TaxTestCase
{
    /** @param array<int, string> $names */
    private function sizeAttribute(array $names): void
    {
        $attrId = DB::table('attributes')->insertGetId(['slug' => 'size', 'name' => 'Size', 'language' => 'en']);
        foreach ($names as $i => $name) {
            DB::table('attribute_values')->insert([
                'attribute_id' => $attrId,
                'value'        => $name,
                'sort_order'   => ($i + 1) * 10,
                'language'     => 'en',
            ]);
        }
    }

    /** @param array<int, string> $sizes */
    private function prodCommand(array $sizes): ApplyProdSizePricingCommand
    {
        $cmd = new ApplyProdSizePricingCommand();
        $prop = (new \ReflectionClass($cmd))->getProperty('sizes');
        $prop->setAccessible(true);
        $prop->setValue($cmd, $sizes);

        return $cmd;
    }

    private function invokePrivate(object $obj, string $method, mixed ...$args): mixed
    {
        $m = (new \ReflectionClass($obj))->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($obj, ...$args);
    }

    public function test_an_empty_attribute_falls_back_to_the_three_the_catalog_launched_with(): void
    {
        $this->assertSame(['Small', 'Medium', 'Large'], sizeNames());
    }

    public function test_the_ladder_comes_from_the_attribute_in_sort_order(): void
    {
        // Deliberately inserted out of order: id order must NOT win.
        $attrId = DB::table('attributes')->insertGetId(['slug' => 'size', 'name' => 'Size', 'language' => 'en']);
        foreach ([['Large', 30], ['Small', 10], ['Extra Large', 40], ['Medium', 20]] as [$name, $sort]) {
            DB::table('attribute_values')->insert([
                'attribute_id' => $attrId, 'value' => $name, 'sort_order' => $sort, 'language' => 'en',
            ]);
        }

        $this->assertSame(['Small', 'Medium', 'Large', 'Extra Large'], sizeNames());
    }

    public function test_a_renamed_size_replaces_the_literal_rather_than_joining_it(): void
    {
        $this->sizeAttribute(['Desk', 'Floor']);

        $this->assertSame(['Desk', 'Floor'], sizeNames());
    }

    public function test_three_sizes_still_price_exactly_min_price_max(): void
    {
        $ladder = $this->invokePrivate($this->prodCommand(['Small', 'Medium', 'Large']), 'ladderAcross', 499.0, 799.0, 1299.0);

        $this->assertSame(['Small' => 499, 'Medium' => 799, 'Large' => 1299], $ladder);
    }

    public function test_a_fourth_size_interpolates_without_moving_either_end(): void
    {
        $ladder = $this->invokePrivate($this->prodCommand(['Small', 'Medium', 'Large', 'Extra Large']), 'ladderAcross', 300.0, 600.0, 1200.0);

        $this->assertSame(300, $ladder['Small']);
        $this->assertSame(1200, $ladder['Extra Large']);
        // Interior sizes climb, and never past the ends.
        $this->assertGreaterThan($ladder['Small'], $ladder['Medium']);
        $this->assertGreaterThan($ladder['Medium'], $ladder['Large']);
        $this->assertLessThan($ladder['Extra Large'], $ladder['Large']);
    }

    public function test_a_single_size_takes_the_products_own_price(): void
    {
        $this->assertSame(['One size' => 799], $this->invokePrivate($this->prodCommand(['One size']), 'ladderAcross', 499.0, 799.0, 1299.0));
    }

    public function test_the_stock_split_preserves_the_total_at_any_ladder_length(): void
    {
        foreach ([['Small', 'Medium', 'Large'], ['Small', 'Medium', 'Large', 'Extra Large'], ['One size']] as $sizes) {
            $split = $this->invokePrivate($this->prodCommand($sizes), 'splitQty', 97);
            $this->assertSame($sizes, array_keys($split));
            $this->assertSame(97, array_sum($split), 'stock must not be invented or lost');
            $this->assertGreaterThan(0, min($split), 'no size may be seeded out of stock');
        }
    }

    public function test_a_stock_count_below_the_ladder_length_gives_every_size_one(): void
    {
        $this->assertSame(
            ['Small' => 1, 'Medium' => 1, 'Large' => 1, 'Extra Large' => 1],
            $this->invokePrivate($this->prodCommand(['Small', 'Medium', 'Large', 'Extra Large']), 'splitQty', 2)
        );
    }

    public function test_the_price_multipliers_keep_climbing_past_the_three_anchors(): void
    {
        $cmd = new ApplySizePricingCommand();

        $this->assertSame(1.0, $this->invokePrivate($cmd, 'multiplier', 0));
        $this->assertSame(1.7, $this->invokePrivate($cmd, 'multiplier', 1));
        $this->assertSame(2.6, $this->invokePrivate($cmd, 'multiplier', 2));
        // Extrapolated by the last step (2.6 - 1.7), so a fourth size is never free.
        $this->assertEqualsWithDelta(3.5, $this->invokePrivate($cmd, 'multiplier', 3), 0.0001);
        $this->assertEqualsWithDelta(4.4, $this->invokePrivate($cmd, 'multiplier', 4), 0.0001);
    }
}
