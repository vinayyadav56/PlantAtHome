<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Marvel\Database\Repositories\CheckoutRepository;
use Marvel\Database\Repositories\OrderRepository;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\CheckoutVerifyRequest;
use Marvel\Http\Requests\OrderCreateRequest;
use Marvel\Services\PricingService;
use Tests\TestCase;

/**
 * Every server-side price, delivery charge and stock decrement keys on a line's
 * variation_option_id — so a line must name a live variant of its OWN product,
 * or none when the product has none. verify() reports the rest line by line and
 * prices around them; order create refuses them before pricing. And a quantity
 * is a whole number >= 1: the charged subtotal multiplies by it raw.
 *
 * Fixture: Snake Plant (1) and Areca Palm (2) are variable, Trowel (3) is simple.
 * Variants: 11 Snake/Small 500, 12 Snake/Large 900 switched off, 21 Areca/Small
 * 100, 31 a leftover row on the simple Trowel.
 */
final class VariantLineGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default'            => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        ]);
        DB::purge('sqlite');

        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->text('options')->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('slug')->nullable();
            $t->string('language')->default('en');
            $t->unsignedBigInteger('type_id')->nullable();
            $t->string('product_type')->default('simple');
            $t->decimal('price')->nullable();
            $t->decimal('sale_price')->nullable();
            $t->decimal('min_price')->nullable();
            $t->boolean('is_digital')->default(false);
            $t->decimal('delivery_charge')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('variation_options', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('product_id');
            $t->string('title')->nullable();
            $t->string('price')->nullable();
            $t->string('sale_price')->nullable();
            $t->boolean('is_disable')->default(false);
            $t->text('options')->nullable();
            $t->timestamps();
        });
        // Empty: every line here prices off the catalogue, as unmapped products do.
        Schema::create('vendor_product_prices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('variation_option_id')->nullable();
            $t->decimal('cost_price', 14, 2)->default(0);
            $t->decimal('vendor_selling_price', 14, 2)->nullable();
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->boolean('is_available')->default(true);
            $t->integer('stock_qty')->default(0);
            $t->integer('reserved_qty')->default(0);
            $t->boolean('track_stock')->default(false);
            $t->string('review_status')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        DB::table('settings')->insert(['options' => json_encode(['minimumOrderAmount' => 5000]), 'language' => 'en']);
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Snake Plant', 'product_type' => 'variable', 'price' => null, 'delivery_charge' => 50],
            ['id' => 2, 'name' => 'Areca Palm', 'product_type' => 'variable', 'price' => null, 'delivery_charge' => 50],
            ['id' => 3, 'name' => 'Trowel', 'product_type' => 'simple', 'price' => 300, 'delivery_charge' => 40],
        ]);
        DB::table('variation_options')->insert([
            ['id' => 11, 'product_id' => 1, 'title' => 'Small', 'price' => '500', 'is_disable' => 0],
            ['id' => 12, 'product_id' => 1, 'title' => 'Large', 'price' => '900', 'is_disable' => 1],
            ['id' => 21, 'product_id' => 2, 'title' => 'Small', 'price' => '100', 'is_disable' => 0],
            ['id' => 31, 'product_id' => 3, 'title' => 'Default', 'price' => '300', 'is_disable' => 0],
        ]);
    }

    private function line(int $productId, ?int $variationOptionId, int $qty = 1): array
    {
        return array_filter([
            'product_id' => $productId, 'variation_option_id' => $variationOptionId,
            'order_quantity' => $qty, 'unit_price' => 1, 'subtotal' => $qty,
        ], fn ($v) => $v !== null);
    }

    public function test_each_line_that_can_never_be_ordered_is_reported_with_why(): void
    {
        $problems = (new CheckoutRepository())->variantLineProblems([
            $this->line(1, 11),     // fine
            $this->line(3, null),   // fine: simple, no variant
            $this->line(1, null),
            $this->line(1, 21),     // Areca's variant on Snake Plant
            $this->line(1, 999999), // deleted
            $this->line(1, 12),
            $this->line(3, 31),
            $this->line(1, 12),     // the same line twice is one problem
        ]);

        $this->assertSame([
            ['product_id' => 1, 'variation_option_id' => null, 'reason' => 'option_missing'],
            ['product_id' => 1, 'variation_option_id' => 21, 'reason' => 'not_found'],
            ['product_id' => 1, 'variation_option_id' => 999999, 'reason' => 'not_found'],
            ['product_id' => 1, 'variation_option_id' => 12, 'reason' => 'disabled'],
            ['product_id' => 3, 'variation_option_id' => 31, 'reason' => 'not_variable'],
        ], $problems);
    }

    public function test_verify_reports_bad_lines_and_leaves_them_out_of_every_total(): void
    {
        $repo = new class extends CheckoutRepository {
            public array $taxed = [];

            public function calculateTax($request, $shipping_charge, $amount)
            {
                $this->taxed = array_map(fn ($l) => [$l['product_id'], $l['variation_option_id'] ?? null], (array) $request['products']);
                return 0;
            }

            public function gstBreakdown($request, $shipping_charge): array
            {
                return ['taxable_amount' => 0, 'cgst_amount' => 0, 'sgst_amount' => 0, 'igst_amount' => 0, 'is_inter_state' => false,
                    'place_of_supply' => null, 'total_tax' => 0, 'delivery_tax_amount' => 0, 'lines' => []];
            }

            public function optimizerFlatFee(float $amount): ?float
            {
                return null;
            }

            protected function optimizedCheckout($request, float $amount, bool $isFullWalletPayment): ?array
            {
                return null;
            }
        };

        // Below the 5000 minimum on purpose: with bad lines out of the total, the
        // shopper must see those lines, not "Minimum order amount".
        $res = $repo->verify(Request::create('/api/orders/checkout/verify', 'POST', ['amount' => 1, 'products' => [
            $this->line(1, 11),
            $this->line(1, 12),     // switched off: was charged 900
            $this->line(1, 21),     // Areca's variant: was charged 100 on a Snake Plant
            $this->line(1, null),
            $this->line(3, null, 2),
        ]]));

        $this->assertSame(1100.0, $res['amount'], '500 + 2 × 300, nothing for the bad lines');
        $this->assertSame(130.0, (float) $res['shipping_charge'], '50 + 2 × 40');
        $this->assertSame([[1, 11], [3, null]], $repo->taxed);
        $this->assertSame([
            ['product_id' => 1, 'variation_option_id' => 12, 'reason' => 'disabled'],
            ['product_id' => 1, 'variation_option_id' => 21, 'reason' => 'not_found'],
            ['product_id' => 1, 'variation_option_id' => null, 'reason' => 'option_missing'],
        ], $res['invalid_variant_lines']);
        $this->assertSame([1], $res['invalid_option_lines'], 'old clients keep their key');
        $this->assertCount(2, $res['lines']);
    }

    public function test_order_create_refuses_the_cart_naming_the_products(): void
    {
        $gate = new CheckoutRepository();
        $gate->assertVariantLines(['products' => [$this->line(1, 11), $this->line(3, null)]]); // passes

        try {
            $gate->assertVariantLines(['products' => [$this->line(1, 11), $this->line(1, 12), $this->line(2, null)]]);
            $this->fail('a switched-off or missing variant must refuse the order');
        } catch (HttpResponseException $e) {
            $this->assertSame(422, $e->getResponse()->getStatusCode());
            $this->assertSame([
                'code'    => 'INVALID_VARIANT',
                'message' => 'The option you picked for Snake Plant and Areca Palm is no longer available — remove it from your cart and add it again.',
                'lines'   => [
                    ['product_id' => 1, 'variation_option_id' => 12, 'reason' => 'disabled'],
                    ['product_id' => 2, 'variation_option_id' => null, 'reason' => 'option_missing'],
                ],
            ], $e->getResponse()->getData(true));
        }
    }

    public function test_past_the_guard_another_products_variant_still_never_sets_a_price(): void
    {
        $line = $this->line(1, 21); // Areca's 100 variant on a Snake Plant

        $priced = (new PricingService())->repriceLines([$line], null, null, catalogFallback: true);
        $this->assertSame(0.0, $priced[0]['unit_price'], "Snake Plant's own (unset) price, never Areca's 100");

        $this->expectException(MarvelException::class);
        app(OrderRepository::class)->calculateSubtotal([$line]);
    }

    public function test_a_quantity_is_a_whole_number_of_at_least_one_on_both_requests(): void
    {
        $requests = [
            'order create' => [(new OrderCreateRequest())->rules(), ['amount' => 500, 'paid_total' => 500, 'total' => 500, 'payment_gateway' => 'CASH_ON_DELIVERY']],
            'verify'       => [(new CheckoutVerifyRequest())->rules(), ['amount' => 500]],
        ];
        foreach ($requests as $name => [$rules, $body]) {
            $valid = Validator::make($body + ['products' => [$this->line(1, 11), $this->line(3, null, 2)]], $rules);
            $this->assertTrue($valid->passes(), "{$name}: " . $valid->errors()->toJson());

            foreach ([-1, 0, 0.5] as $qty) {
                $bad = Validator::make($body + ['products' => [['order_quantity' => $qty] + $this->line(1, 11)]], $rules);
                $this->assertTrue($bad->errors()->has('products.0.order_quantity'), "{$name} must refuse order_quantity {$qty}");
            }
        }
    }
}
