<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The in-memory marketplace schema the catalogue / availability / checkout / assignment
 * tests share (types, the Operations Control Center tables, cities + postal master, shops,
 * products, vendor supply + the city projection, orders, items, shipments, variations).
 */
trait MarketplaceSchema
{
    private function marketplaceSchema(): void
    {
        $tables = [
            'settings' => fn (Blueprint $t) => [$t->text('options')->nullable(), $t->string('language')->default('en'), $t->timestamps()],
            'types' => fn (Blueprint $t) => [$t->string('name'), $t->string('slug'), $t->string('language')->default('en'), $t->json('settings')->nullable(), $t->timestamps()],
            'global_vertical_settings' => fn (Blueprint $t) => [
                $t->string('vertical_slug', 64)->unique(), $t->boolean('is_active')->default(true), $t->string('status', 24)->default('active'),
                $t->text('maintenance_message')->nullable(), $t->json('settings')->nullable(),
                $t->unsignedBigInteger('created_by')->nullable(), $t->unsignedBigInteger('updated_by')->nullable(), $t->timestamps(),
            ],
            'city_vertical_service_settings' => fn (Blueprint $t) => [
                $t->unsignedBigInteger('city_id'), $t->string('vertical_slug'), $t->string('status'), $t->text('maintenance_message')->nullable(), $t->timestamps(),
            ],
            'service_availability_logs' => fn (Blueprint $t) => [
                $t->string('entity_type'), $t->string('entity_id')->nullable(), $t->json('old_value')->nullable(), $t->json('new_value')->nullable(),
                $t->unsignedBigInteger('changed_by')->nullable(), $t->text('reason')->nullable(), $t->string('ip')->nullable(), $t->timestamp('created_at')->nullable(),
            ],
            'cities' => fn (Blueprint $t) => [
                $t->string('name'), $t->string('state_name')->nullable(), $t->decimal('lat', 10, 7)->nullable(), $t->decimal('lng', 10, 7)->nullable(),
                $t->string('status')->default('active'), $t->boolean('is_serviceable')->default(true), $t->json('settings')->nullable(),
            ],
            'states' => fn (Blueprint $t) => [$t->string('name')],
            'districts' => fn (Blueprint $t) => [$t->string('name')],
            'postal_codes' => fn (Blueprint $t) => [
                $t->string('pincode'), $t->unsignedBigInteger('state_id')->nullable(), $t->unsignedBigInteger('district_id')->nullable(),
                $t->unsignedBigInteger('city_id')->nullable(), $t->decimal('latitude', 10, 7)->nullable(), $t->decimal('longitude', 10, 7)->nullable(),
                $t->string('status', 16)->default('active'),
            ],
            'shops' => fn (Blueprint $t) => [
                $t->string('name')->nullable(), $t->string('slug')->nullable(), $t->unsignedBigInteger('owner_id')->nullable(),
                $t->boolean('is_active')->default(true), $t->string('approval_status')->nullable(), $t->text('address')->nullable(),
                $t->text('settings')->nullable(), $t->decimal('lat', 10, 7)->nullable(), $t->decimal('lng', 10, 7)->nullable(),
                $t->decimal('vendor_rating', 3, 2)->nullable(), $t->integer('vendor_priority_score')->nullable(), $t->integer('sla_default_days')->nullable(),
                $t->string('delivery_mode')->default('platform'), $t->timestamps(),
            ],
            'products' => fn (Blueprint $t) => [
                $t->string('name')->nullable(), $t->string('slug')->nullable(), $t->string('language')->default('en'),
                $t->unsignedBigInteger('type_id')->nullable(), $t->unsignedBigInteger('shop_id')->nullable(), $t->string('product_type')->default('simple'),
                $t->string('status')->default('publish'), $t->decimal('price')->nullable(), $t->decimal('sale_price')->nullable(),
                $t->decimal('min_price')->nullable(), $t->decimal('max_price')->nullable(), $t->boolean('in_stock')->default(true),
                $t->boolean('is_digital')->default(false), $t->decimal('delivery_charge')->nullable(), $t->integer('quantity')->default(0),
                $t->boolean('is_available_product')->default(true), $t->boolean('listing_enabled')->default(true), $t->timestamps(), $t->softDeletes(),
            ],
            'categories' => fn (Blueprint $t) => [$t->string('name')->nullable(), $t->timestamps()],
            'category_product' => fn (Blueprint $t) => [$t->unsignedBigInteger('product_id'), $t->unsignedBigInteger('category_id')],
            'pricing_margins' => fn (Blueprint $t) => [
                $t->string('city')->nullable(), $t->unsignedBigInteger('type_id')->nullable(), $t->string('margin_type')->default('percent'),
                $t->decimal('margin_percent', 8, 2)->default(0), $t->decimal('margin_flat', 12, 2)->nullable(), $t->boolean('is_active')->default(true), $t->timestamps(),
            ],
            'vendor_product_prices' => fn (Blueprint $t) => [
                $t->unsignedBigInteger('shop_id'), $t->unsignedBigInteger('product_id'), $t->unsignedBigInteger('variation_option_id')->nullable(),
                $t->decimal('cost_price', 14, 2)->default(0), $t->decimal('vendor_selling_price', 14, 2)->nullable(), $t->string('period_type')->nullable(),
                $t->date('effective_from')->nullable(), $t->date('effective_to')->nullable(), $t->boolean('is_available')->default(true),
                $t->integer('stock_qty')->default(0), $t->integer('reserved_qty')->default(0), $t->boolean('track_stock')->default(false),
                $t->string('fulfillment_mode')->nullable(), $t->string('review_status')->nullable(), $t->timestamp('submitted_at')->nullable(),
                $t->timestamp('approved_at')->nullable(), $t->string('source')->nullable(), $t->unsignedBigInteger('created_by_user_id')->nullable(),
                $t->unsignedBigInteger('updated_by_user_id')->nullable(), $t->string('dedupe_key')->nullable(), $t->timestamps(), $t->softDeletes(),
            ],
            'vendor_service_areas' => fn (Blueprint $t) => [
                $t->unsignedBigInteger('shop_id'), $t->string('city')->nullable(), $t->string('pincode')->nullable(),
                $t->string('fulfillment_mode')->nullable(), $t->integer('eta_days')->nullable(), $t->boolean('is_active')->default(true), $t->timestamps(),
            ],
            'vendor_shipping_rates' => fn (Blueprint $t) => [
                $t->unsignedBigInteger('shop_id'), $t->string('fulfillment_mode')->nullable(), $t->string('zone')->nullable(),
                $t->decimal('base_cost')->default(0), $t->decimal('per_kg_cost')->default(0), $t->boolean('is_active')->default(true), $t->timestamps(),
            ],
            'product_city_availability' => fn (Blueprint $t) => [
                $t->unsignedBigInteger('product_id'), $t->string('city'), $t->unsignedBigInteger('variation_option_id')->default(0),
                $t->boolean('has_local')->default(false), $t->boolean('has_courier')->default(false), $t->decimal('min_price', 12, 2)->nullable(),
                $t->decimal('display_price', 14, 2)->nullable(), $t->integer('stock')->nullable(), $t->integer('stock_override')->nullable(),
                $t->integer('vendor_count')->default(0), $t->timestamp('updated_at')->nullable(),
            ],
            'orders' => fn (Blueprint $t) => [
                $t->string('tracking_number'), $t->unsignedBigInteger('customer_id')->nullable(), $t->unsignedBigInteger('parent_id')->nullable(),
                $t->string('order_status')->nullable(), $t->string('payment_status')->nullable(), $t->string('payment_gateway')->nullable(), $t->unsignedBigInteger('vendor_shop_id')->nullable(),
                $t->text('shipping_address')->nullable(), $t->string('language')->default('en'), $t->timestamps(), $t->softDeletes(),
            ],
            'order_items' => fn (Blueprint $t) => [
                $t->unsignedBigInteger('order_id'), $t->unsignedBigInteger('product_id')->nullable(), $t->unsignedBigInteger('variation_option_id')->nullable(),
                $t->integer('order_quantity')->default(1), $t->decimal('unit_price')->default(0), $t->unsignedBigInteger('assigned_shop_id')->nullable(),
                $t->unsignedBigInteger('vendor_product_price_id')->nullable(), $t->integer('reserved_qty')->default(0), $t->string('fulfillment_mode')->nullable(),
                $t->integer('eta_days')->nullable(), $t->unsignedBigInteger('shipment_id')->nullable(), $t->string('split_group', 16)->nullable(),
                $t->string('vendor_price_snapshot')->nullable(), $t->string('assignment_status')->default('unassigned'), $t->string('item_status')->default('pending'), $t->timestamps(),
            ],
            'shipments' => fn (Blueprint $t) => [
                $t->unsignedBigInteger('order_id'), $t->unsignedBigInteger('shop_id')->nullable(), $t->unsignedBigInteger('pickup_location_id')->nullable(),
                $t->string('fulfillment_mode')->nullable(), $t->string('delivery_mode')->nullable(), $t->string('status')->default('pending'),
                $t->decimal('shipping_cost')->nullable(), $t->integer('eta_days')->nullable(), $t->date('expected_delivery_at')->nullable(),
                $t->string('provider')->nullable(), $t->string('awb_number')->nullable(), $t->string('cancelled_reason')->nullable(), $t->timestamp('cancelled_at')->nullable(),
                $t->string('provider_order_id')->nullable(), $t->string('provider_shipment_id')->nullable(), $t->string('split_group', 16)->nullable(), $t->timestamps(),
            ],
            'shipment_items' => fn (Blueprint $t) => [
                $t->unsignedBigInteger('shipment_id'), $t->unsignedBigInteger('order_item_id'), $t->unsignedInteger('quantity')->default(1),
                $t->string('status', 32)->default('pending'), $t->timestamps(),
            ],
            'order_events' => fn (Blueprint $t) => [
                $t->unsignedBigInteger('order_id'), $t->string('type', 64), $t->string('label')->nullable(), $t->string('actor_type', 32)->nullable(),
                $t->unsignedBigInteger('actor_id')->nullable(), $t->json('meta')->nullable(), $t->timestamp('created_at')->nullable(),
            ],
            'users' => fn (Blueprint $t) => [$t->string('name')->nullable(), $t->timestamps()],
            'order_product' => fn (Blueprint $t) => [
                $t->unsignedBigInteger('order_id'), $t->unsignedBigInteger('product_id'), $t->unsignedBigInteger('variation_option_id')->nullable(),
                $t->integer('order_quantity')->nullable(), $t->string('unit_price')->nullable(), $t->string('subtotal')->nullable(), $t->timestamps(),
            ],
            'variation_options' => fn (Blueprint $t) => [
                $t->unsignedBigInteger('product_id'), $t->string('title'), $t->string('price'), $t->string('sale_price')->nullable(),
                $t->unsignedBigInteger('quantity')->default(0), $t->boolean('is_disable')->default(false), $t->string('sku')->nullable(),
                $t->json('options')->nullable(), $t->string('language')->default('en'), $t->timestamps(),
            ],
        ];
        foreach ($tables as $name => $cols) {
            Schema::create($name, function (Blueprint $t) use ($cols) {
                $t->bigIncrements('id');
                $cols($t);
            });
        }
    }
}
