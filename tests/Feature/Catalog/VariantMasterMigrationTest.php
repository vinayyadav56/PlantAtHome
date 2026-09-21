<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 2026_09_22_000000_add_variant_master_columns_to_attribute_values.
 *
 * Promotes the Size attribute to the variant master: a stable code, a display
 * order and the per-size delivery charge the customer pays. The seed has to
 * survive the mess production actually has — five attributes named "Size"
 * (the 2026-09-17 merge is best-effort), values that differ in case and
 * whitespace, and a re-run must never overwrite a charge an admin has edited.
 *
 * sqlite with hand-built tables, the MergeDuplicateAttributesTest idiom; the
 * migration uses the query builder throughout and no MySQL-only SQL.
 */
final class VariantMasterMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default'            => 'sqlite',
            'database.connections.sqlite' => [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');

        Schema::create('attributes', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('slug');
            $t->string('name');
            $t->string('language')->default('en');
        });
        Schema::create('attribute_values', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('attribute_id');
            $t->string('value');
            $t->string('language')->default('en');
        });
    }

    private function runMigration(): void
    {
        (require base_path('packages/marvel/database/migrations/2026_09_22_000000_add_variant_master_columns_to_attribute_values.php'))->up();
    }

    private function attribute(string $name = 'Size', string $slug = 'size'): int
    {
        return DB::table('attributes')->insertGetId(['slug' => $slug, 'name' => $name, 'language' => 'en']);
    }

    private function value(int $attributeId, string $value): int
    {
        return DB::table('attribute_values')->insertGetId([
            'attribute_id' => $attributeId, 'value' => $value, 'language' => 'en',
        ]);
    }

    private function row(int $id): object
    {
        return DB::table('attribute_values')->where('id', $id)->first();
    }

    public function test_it_seeds_the_owners_charges_and_the_display_order(): void
    {
        $size = $this->attribute();
        $small = $this->value($size, 'Small');
        $medium = $this->value($size, 'Medium');
        $large = $this->value($size, 'Large');

        $this->runMigration();

        $this->assertSame('S', $this->row($small)->code);
        $this->assertSame('M', $this->row($medium)->code);
        $this->assertSame('L', $this->row($large)->code);

        $this->assertEquals(100, $this->row($small)->delivery_charge);
        $this->assertEquals(150, $this->row($medium)->delivery_charge);
        $this->assertEquals(200, $this->row($large)->delivery_charge);

        $this->assertSame([1, 2, 3], [
            (int) $this->row($small)->sort_order,
            (int) $this->row($medium)->sort_order,
            (int) $this->row($large)->sort_order,
        ]);
    }

    public function test_it_seeds_every_size_attribute_not_just_the_first(): void
    {
        // Production carried five; the merge that folds them is best effort, so a
        // product still attached to a straggler must price the same as any other.
        $first = $this->value($this->attribute(), 'Large');
        $straggler = $this->value($this->attribute(), 'Large');

        $this->runMigration();

        $this->assertEquals(200, $this->row($first)->delivery_charge);
        $this->assertEquals(200, $this->row($straggler)->delivery_charge);
    }

    public function test_it_matches_values_regardless_of_case_or_padding(): void
    {
        $size = $this->attribute();
        $shouty = $this->value($size, 'LARGE');
        $padded = $this->value($size, '  small  ');

        $this->runMigration();

        $this->assertSame('L', $this->row($shouty)->code);
        $this->assertSame('S', $this->row($padded)->code);
    }

    public function test_it_leaves_other_attributes_alone(): void
    {
        $colour = $this->value($this->attribute('Colour', 'colour'), 'Large');

        $this->runMigration();

        $this->assertNull($this->row($colour)->code, 'a Colour called "Large" is not a size');
        $this->assertNull($this->row($colour)->delivery_charge);
    }

    public function test_a_rerun_never_overwrites_an_edited_charge(): void
    {
        $size = $this->attribute();
        $large = $this->value($size, 'Large');
        $this->runMigration();

        DB::table('attribute_values')->where('id', $large)->update(['delivery_charge' => 275.00]);
        $this->runMigration();

        $this->assertEquals(275, $this->row($large)->delivery_charge, 'the admin edit is the newer truth');
    }

    public function test_it_adds_the_uniqueness_the_values_never_had(): void
    {
        $size = $this->attribute();
        $this->value($size, 'Small');

        $this->runMigration();

        $this->assertTrue($this->indexExists('attribute_values', 'attribute_values_attribute_value_language_unique'));
        $this->assertTrue($this->indexExists('attribute_values', 'attribute_values_attribute_code_unique'));
    }

    public function test_it_skips_the_index_rather_than_failing_a_deploy_on_dirty_data(): void
    {
        $size = $this->attribute();
        $this->value($size, 'Small');
        $this->value($size, 'Small'); // the duplicate the index would reject

        $this->runMigration(); // must not throw

        $this->assertFalse($this->indexExists('attribute_values', 'attribute_values_attribute_value_language_unique'));
        $this->assertEquals(100, $this->row(1)->delivery_charge, 'the seed still runs');
    }

    private function indexExists(string $table, string $index): bool
    {
        foreach (DB::select("PRAGMA index_list(\"{$table}\")") as $row) {
            if (($row->name ?? null) === $index) {
                return true;
            }
        }

        return false;
    }
}
