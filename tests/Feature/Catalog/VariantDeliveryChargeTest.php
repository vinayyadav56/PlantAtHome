<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Http\Controllers\VariantDeliveryChargeController;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Admin -> Pricing -> Variant Delivery Charges.
 *
 * The screen edits three columns on the Size attribute's values, and every
 * variable product on the platform references those rows — so the endpoint is
 * deliberately narrow: no create, no delete, sizes only, and a code that cannot
 * collide with another size's.
 */
final class VariantDeliveryChargeTest extends TestCase
{
    private int $sizeAttributeId;

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

        Schema::create('attributes', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('slug')->nullable();
            $t->string('name')->nullable();
            $t->string('language')->default('en');
        });
        Schema::create('attribute_values', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('attribute_id');
            $t->string('value')->nullable();
            $t->string('slug')->nullable();
            $t->string('code', 8)->nullable();
            $t->integer('sort_order')->default(0);
            $t->decimal('delivery_charge', 10, 2)->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
        });
        Schema::create('attribute_product', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('attribute_value_id');
        });
        Schema::create('products', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->softDeletes();
        });
        Schema::create('acc_audit_log', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('auditable_type', 64);
            $t->string('auditable_id', 64);
            $t->string('action', 40);
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->text('reason')->nullable();
            $t->string('reference', 191)->nullable();
            $t->string('actor_type', 16)->nullable();
            $t->string('actor_id', 64)->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('created_at')->nullable();
        });

        $this->sizeAttributeId = DB::table('attributes')->insertGetId(['slug' => 'size', 'name' => 'Size', 'language' => 'en']);
    }

    private function controller(): VariantDeliveryChargeController
    {
        return app(VariantDeliveryChargeController::class);
    }

    private function sizeRow(string $value, ?string $code, int $sortOrder, ?float $charge, ?int $attributeId = null): int
    {
        return DB::table('attribute_values')->insertGetId([
            'attribute_id' => $attributeId ?? $this->sizeAttributeId,
            'value' => $value, 'code' => $code, 'sort_order' => $sortOrder,
            'delivery_charge' => $charge, 'language' => 'en',
        ]);
    }

    private function update(int $id, array $payload)
    {
        return $this->controller()->update(Request::create('/', 'PUT', $payload), (string) $id);
    }

    public function test_it_lists_the_master_in_display_order_with_reach(): void
    {
        $large = $this->sizeRow('Large', 'L', 3, 200.0);
        $small = $this->sizeRow('Small', 'S', 1, 100.0);
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Areca Palm'],
            ['id' => 2, 'name' => 'Snake Plant'],
        ]);
        DB::table('attribute_product')->insert([
            ['product_id' => 1, 'attribute_value_id' => $small],
            ['product_id' => 2, 'attribute_value_id' => $small],
        ]);

        $rows = $this->controller()->index(Request::create('/', 'GET'))->all();

        $this->assertSame(['Small', 'Large'], array_column($rows, 'value'));
        $this->assertSame(100.0, $rows[0]['delivery_charge']);
        $this->assertSame(2, $rows[0]['products_count'], 'how many products this edit reprices');
        $this->assertSame($large, $rows[1]['id']);
    }

    public function test_it_saves_a_new_charge(): void
    {
        $id = $this->sizeRow('Large', 'L', 3, 200.0);

        $this->update($id, ['delivery_charge' => 249.50]);

        $this->assertEquals(249.50, DB::table('attribute_values')->where('id', $id)->value('delivery_charge'));
    }

    public function test_clearing_a_charge_falls_back_rather_than_shipping_free(): void
    {
        $id = $this->sizeRow('Large', 'L', 3, 200.0);

        $this->update($id, ['delivery_charge' => null]);

        $this->assertNull(DB::table('attribute_values')->where('id', $id)->value('delivery_charge'));
    }

    public function test_it_records_who_changed_what(): void
    {
        $id = $this->sizeRow('Medium', 'M', 2, 150.0);

        $this->update($id, ['delivery_charge' => 175.0]);

        $row = DB::table('acc_audit_log')->where('auditable_type', 'variant_delivery_charge')->first();
        $this->assertNotNull($row, 'a money change must leave a trail');
        $this->assertSame('updated', $row->action);
        $this->assertSame((string) $id, (string) $row->auditable_id);
        $this->assertStringContainsString('150', $row->before);
        $this->assertStringContainsString('175', $row->after);
    }

    public function test_an_unchanged_save_writes_no_audit_noise(): void
    {
        $id = $this->sizeRow('Medium', 'M', 2, 150.0);

        $this->update($id, ['delivery_charge' => 150.0]);

        $this->assertSame(0, DB::table('acc_audit_log')->count());
    }

    public function test_two_sizes_cannot_share_a_code(): void
    {
        $this->sizeRow('Small', 'S', 1, 100.0);
        $medium = $this->sizeRow('Medium', 'M', 2, 150.0);

        $this->expectException(HttpException::class);
        $this->update($medium, ['code' => 's']);
    }

    public function test_only_sizes_carry_a_delivery_charge(): void
    {
        $colourId = DB::table('attributes')->insertGetId(['slug' => 'colour', 'name' => 'Colour', 'language' => 'en']);
        $terracotta = $this->sizeRow('Terracotta', null, 1, null, $colourId);

        $this->expectException(HttpException::class);
        $this->update($terracotta, ['delivery_charge' => 500.0]);
    }

    public function test_a_negative_charge_is_refused(): void
    {
        $id = $this->sizeRow('Large', 'L', 3, 200.0);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->update($id, ['delivery_charge' => -50]);
    }
}
