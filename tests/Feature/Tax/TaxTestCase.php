<?php

namespace Tests\Feature\Tax;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * GST engine harness: sqlite :memory: with a hand-built minimal schema (products,
 * tax_classes, states, settings) — just the columns GstService touches. Fast and
 * hermetic; no dependency on the full Marvel migration set.
 */
abstract class TaxTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');

        Schema::create('states', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('code', 8)->nullable();
            $t->boolean('is_active')->default(true);
        });
        Schema::create('tax_classes', function ($t) {
            $t->id();
            $t->double('rate')->default(0);
            $t->string('name')->nullable();
            $t->string('hsn_code', 16)->nullable();
            $t->string('tax_category', 24)->default('taxable');
            $t->boolean('is_active')->default(true);
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->timestamps();
        });
        Schema::create('products', function ($t) {
            $t->id();
            $t->string('hsn_code', 16)->nullable();
            $t->unsignedBigInteger('tax_rate_id')->nullable();
            $t->boolean('tax_inclusive')->nullable();
            $t->boolean('is_taxable')->default(false);
            $t->boolean('tax_verified')->default(false);
            $t->decimal('delivery_charge', 10, 2)->nullable();
            $t->softDeletes();
        });
        // The variant master: delivery is charged by SIZE, resolved off the
        // variation option's options JSON.
        Schema::create('attributes', function ($t) {
            $t->id();
            $t->string('slug')->nullable();
            $t->string('name')->nullable();
            $t->string('language')->default('en');
        });
        Schema::create('attribute_values', function ($t) {
            $t->id();
            $t->unsignedBigInteger('attribute_id');
            $t->string('value')->nullable();
            $t->string('code', 8)->nullable();
            $t->integer('sort_order')->default(0);
            $t->decimal('delivery_charge', 10, 2)->nullable();
            $t->string('language')->default('en');
        });
        Schema::create('variation_options', function ($t) {
            $t->id();
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('title')->nullable();
            $t->text('options')->nullable();
        });
        Schema::create('settings', function ($t) {
            $t->id();
            $t->json('options')->nullable();
            $t->string('language')->default('en');
        });

        DB::table('states')->insert([
            ['name' => 'Haryana', 'code' => 'HR', 'is_active' => 1],
            ['name' => 'Delhi', 'code' => 'DL', 'is_active' => 1],
            ['name' => 'Maharashtra', 'code' => 'MH', 'is_active' => 1],
        ]);
    }

    /** Set the store business-tax config (origin Haryana, inclusive by default). */
    protected function business(array $overrides = []): void
    {
        $tax = array_merge([
            'prices_include_tax' => true,
            'gstin' => '06ABCDE1234F1Z5',
            'legal_name' => 'PlantAtHome',
            'registration_state' => 'Haryana',
            'registration_state_code' => 'HR',
            'delivery_tax_treatment' => 'follow_principal',
        ], $overrides);
        DB::table('settings')->truncate();
        DB::table('settings')->insert([
            'options' => json_encode(['tax' => $tax]),
            'language' => 'en',
        ]);
    }

    /** Create a tax config row; returns its id. */
    protected function taxRate(float $rate, string $category = 'taxable', ?string $hsn = null, array $extra = []): int
    {
        return DB::table('tax_classes')->insertGetId(array_merge([
            'rate' => $rate, 'name' => "GST {$rate}%", 'hsn_code' => $hsn,
            'tax_category' => $category, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));
    }

    /** Create a product referencing a tax config; returns its id. */
    protected function product(?int $taxRateId, ?string $hsn = null, ?bool $inclusive = null): int
    {
        return DB::table('products')->insertGetId([
            'tax_rate_id' => $taxRateId, 'hsn_code' => $hsn,
            'tax_inclusive' => $inclusive, 'is_taxable' => $taxRateId ? 1 : 0,
        ]);
    }

    /** A cart line for GstService. */
    protected function line(int $productId, float $unitPrice, int $qty = 1): array
    {
        return [
            'product_id' => $productId,
            'variation_option_id' => null,
            'order_quantity' => $qty,
            'unit_price' => $unitPrice,
            'subtotal' => $unitPrice * $qty,
        ];
    }

    /**
     * Seed the Size attribute with the owner's charges.
     *
     * @return array<string, int> value => attribute_values.id
     */
    protected function sizeMaster(array $charges = ['Small' => 100.0, 'Medium' => 150.0, 'Large' => 200.0]): array
    {
        $attributeId = DB::table('attributes')->insertGetId(['slug' => 'size', 'name' => 'Size', 'language' => 'en']);
        $codes = ['Small' => 'S', 'Medium' => 'M', 'Large' => 'L'];
        $ids = [];
        $order = 0;
        foreach ($charges as $value => $charge) {
            $ids[$value] = DB::table('attribute_values')->insertGetId([
                'attribute_id' => $attributeId,
                'value' => $value,
                'code' => $codes[$value] ?? null,
                'sort_order' => ++$order,
                'delivery_charge' => $charge,
                'language' => 'en',
            ]);
        }

        return $ids;
    }

    /**
     * A variation option. Pass $valueId for the admin-built shape (options carry
     * the attribute_values id); leave it null for the seeded shape, which has
     * only the value text — both exist in production.
     */
    protected function variationOption(int $productId, string $size, ?int $valueId = null, string $name = 'Size'): int
    {
        $option = ['name' => $name, 'value' => $size];
        if ($valueId !== null) {
            $option['id'] = $valueId;
        }

        return DB::table('variation_options')->insertGetId([
            'product_id' => $productId,
            'title' => $size,
            'options' => json_encode([$option]),
        ]);
    }

    /** Shipping address in a given state. */
    protected function shipTo(string $state): array
    {
        return ['state' => $state, 'city' => 'Test', 'zip' => '123456'];
    }
}
