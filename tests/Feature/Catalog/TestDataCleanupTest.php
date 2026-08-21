<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Services\TestDataCleanup\CleanupPlan;
use Marvel\Services\TestDataCleanup\CleanupRunner;
use Marvel\Services\TestDataCleanup\OrdersCleaner;
use Marvel\Services\TestDataCleanup\UsersCleaner;
use Marvel\Services\TestDataCleanup\VendorInventoryCleaner;
use Marvel\Services\TestDataCleanup\VendorsCleaner;
use Tests\TestCase;

/**
 * The safety properties of the cleanup system — these tests exist to catch the failure modes
 * that would be unrecoverable in production: touching the catalog, deleting the master shop,
 * orphaning children, or losing data with no way back.
 */
final class TestDataCleanupTest extends TestCase
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
        putenv('TESTDATA_ENV=staging');

        Schema::create('test_data_cleanup_runs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('module')->nullable();
            $t->string('mode')->default('preview');
            $t->string('environment')->nullable();
            $t->text('scope')->nullable();
            $t->text('table_counts')->nullable();
            $t->unsignedInteger('total_rows')->default(0);
            $t->longText('snapshot')->nullable();
            $t->string('backup_reference')->nullable();
            $t->unsignedBigInteger('actor_user_id')->nullable();
            $t->string('actor_name')->nullable();
            $t->string('ip')->nullable();
            $t->string('status')->default('pending');
            $t->text('error')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
        Schema::create('test_data_marks', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('markable_type');
            $t->unsignedBigInteger('markable_id');
            $t->string('reason')->nullable();
            $t->unsignedBigInteger('marked_by')->nullable();
            $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('tracking_number')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->unsignedBigInteger('shop_id')->nullable();
            $t->unsignedBigInteger('vendor_shop_id')->nullable();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->string('order_status')->default('order-pending');
            $t->string('payment_status')->default('payment-pending');
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('order_product', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('product_id');
        });
        Schema::create('order_items', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('product_id')->nullable();
        });
        // Allocation ledger: WHICH UNITS of an order line ride on WHICH shipment. Shipment::items()
        // is a belongsToMany through this table, so every stub that has `shipments` needs it too.
        Schema::create('shipment_items', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('shipment_id');
            $t->unsignedBigInteger('order_item_id');
            $t->unsignedInteger('quantity')->default(1);
            $t->string('status', 32)->default('pending');
            $t->timestamps();
        });
        Schema::create('shipment_packages', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('shipment_id');
            $t->unsignedSmallInteger('package_number')->default(1);
            $t->unsignedInteger('weight_g')->nullable();
            $t->decimal('length_cm', 6, 2)->nullable();
            $t->decimal('breadth_cm', 6, 2)->nullable();
            $t->decimal('height_cm', 6, 2)->nullable();
            $t->decimal('declared_value', 14, 2)->nullable();
            $t->string('contents', 255)->nullable();
            $t->boolean('fragile')->default(false);
            $t->timestamps();
        });
        Schema::create('shipments', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('shop_id')->nullable();
        });
        Schema::create('order_events', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
            $t->string('event')->nullable();
        });
        Schema::create('partner_console_orders', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id')->nullable();
            $t->unsignedBigInteger('shipment_id')->nullable();
        });
        Schema::create('partner_webhook_events', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('partner_console_order_id')->nullable();
            $t->unsignedBigInteger('shipment_id')->nullable();
        });
        Schema::create('payment_intents', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('order_id');
        });
        Schema::create('products', function (Blueprint $t) {
            // Master Catalog membership + listing switch. Defaulted TRUE in stubs, not FALSE:
            // production starts empty by design, but a fixture that had to opt every product in
            // would make each existing test assert the new gate instead of what it was written for.
            $t->boolean('is_available_product')->default(true);
            $t->boolean('listing_enabled')->default(true);
            $t->timestamp('available_at')->nullable();
            $t->unsignedBigInteger('available_by')->nullable();
            $t->boolean('track_stock')->default(false);
            $t->bigIncrements('id');
            $t->string('name');
            $t->unsignedBigInteger('shop_id')->nullable();
            $t->unsignedBigInteger('proposed_by_shop_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('shops', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('slug');
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->timestamps();
        });
        // The V2 vendor context. A nursery IS the same vendor under the DDD modules, and it
        // outlives its legacy shop — which is exactly the case the sweep has to cover.
        Schema::create('nursery_nurseries', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('uuid')->nullable();
            $t->string('name');
            $t->string('slug');
            $t->unsignedBigInteger('legacy_id')->nullable();
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->unsignedBigInteger('shop_id')->nullable();
            $t->unsignedBigInteger('reporting_manager_id')->nullable();
            $t->timestamps();
        });
        Schema::create('address', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('customer_id');
        });
        Schema::create('vendor_product_prices', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('import_batch_id')->nullable();
            $t->string('review_status')->default('approved');
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('vendor_inventory_reviews', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('vendor_product_price_id');
            $t->unsignedBigInteger('shop_id')->nullable();
        });

        // Master shop owns the catalog (the real-world shape that makes shop deletion lethal).
        DB::table('shops')->insert([
            ['id' => 12, 'name' => 'PlantAtHome', 'slug' => 'plantathome', 'owner_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 33, 'name' => 'Test Nursery', 'slug' => 'test-nursery', 'owner_id' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Snake Plant', 'shop_id' => 12, 'proposed_by_shop_id' => 33, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Areca Palm', 'shop_id' => 12, 'proposed_by_shop_id' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Admin', 'email' => 'admin@x.test', 'shop_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Vendor Owner', 'email' => 'vendor@x.test', 'shop_id' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Test Buyer', 'email' => 'buyer@plantathome.test', 'shop_id' => 33, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('orders')->insert([
            ['id' => 100, 'tracking_number' => 'T100', 'customer_id' => 3, 'shop_id' => 33, 'parent_id' => null, 'payment_status' => 'payment-success', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 101, 'tracking_number' => 'T101', 'customer_id' => 3, 'shop_id' => 33, 'parent_id' => 100, 'payment_status' => 'payment-pending', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('order_product')->insert([['order_id' => 100, 'product_id' => 1]]);
        DB::table('order_items')->insert([['order_id' => 100, 'product_id' => 1]]);
        DB::table('shipments')->insert([['id' => 500, 'order_id' => 100, 'shop_id' => 33]]);
        DB::table('order_events')->insert([['order_id' => 100, 'event' => 'created']]);
        DB::table('partner_console_orders')->insert([['id' => 900, 'order_id' => 100, 'shipment_id' => 500]]);
        DB::table('partner_webhook_events')->insert([['partner_console_order_id' => 900, 'shipment_id' => 500]]);
        DB::table('payment_intents')->insert([['order_id' => 100]]);
        DB::table('address')->insert([['customer_id' => 3]]);
        DB::table('vendor_product_prices')->insert([
            ['id' => 7, 'shop_id' => 33, 'product_id' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('vendor_inventory_reviews')->insert([['vendor_product_price_id' => 7, 'shop_id' => 33]]);
    }

    public function test_orders_cleanup_removes_the_whole_graph_including_no_fk_children(): void
    {
        $runner = new CleanupRunner();
        $plan = (new OrdersCleaner())->plan(['ids' => [100]]);

        // The sub-order is pulled in automatically — leaving it would strand it.
        $orderStep = collect($plan->steps)->firstWhere('table', 'orders');
        $this->assertEqualsCanonicalizing([100, 101], $orderStep['ids']);

        $runner->execute(new OrdersCleaner(), ['ids' => [100]], [], null);

        foreach (['orders', 'order_product', 'order_items', 'shipments', 'order_events',
                  'partner_console_orders', 'partner_webhook_events', 'payment_intents'] as $t) {
            $this->assertSame(0, DB::table($t)->count(), "{$t} should be empty");
        }
        // The catalog is untouched.
        $this->assertSame(2, DB::table('products')->count());
    }

    public function test_paid_orders_raise_a_warning_before_anything_is_deleted(): void
    {
        $preview = (new CleanupRunner())->preview(new OrdersCleaner(), ['ids' => [100]]);
        $this->assertStringContainsString('SUCCESSFUL PAYMENT', implode(' ', $preview['warnings']));
        $this->assertSame(2, DB::table('orders')->count(), 'preview must not delete');
    }

    /** 🔴 The catastrophic case: products.shop_id CASCADES from the master shop. */
    public function test_master_shop_can_never_be_planned_for_deletion(): void
    {
        $plan = (new VendorsCleaner())->plan(['ids' => [12, 33]]);
        $shopStep = collect($plan->steps)->firstWhere('table', 'shops');

        $this->assertNotContains(12, $shopStep['ids'], 'the master catalog shop must be excluded');
        $this->assertStringContainsString('master catalog shop', implode(' ', $plan->warnings));
    }

    public function test_a_full_wipe_takes_orphaned_nurseries_but_never_the_master(): void
    {
        // A nursery outlives its legacy shop. Staging carried two whose shops were already gone,
        // and a sweep keyed off the shops being deleted never reached them — leaving live vendor
        // records behind a wipe that reported success.
        if (!\Illuminate\Support\Facades\Schema::hasTable('nursery_nurseries')) {
            $this->markTestSkipped('V2 nursery tables are not in this stub schema.');
        }
        DB::table('nursery_nurseries')->insert([
            ['id' => 10, 'uuid' => 'u-master', 'name' => 'PlantAtHome', 'slug' => 'plantathome', 'legacy_id' => 12, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 19, 'uuid' => 'u-orphan', 'name' => 'Gone Nursery', 'slug' => 'gone-nursery', 'legacy_id' => 999, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $plan = (new VendorsCleaner())->plan(['all' => true]);
        $step = collect($plan->steps)->firstWhere('table', 'nursery_nurseries');

        $this->assertNotNull($step, 'a full wipe must plan the vendor nurseries');
        $this->assertContains(19, $step['ids'], 'an orphaned vendor nursery must be swept');
        $this->assertNotContains(10, $step['ids'], 'the master nursery is the catalogue holder, not a vendor');
        $this->assertStringContainsString('Orphaned vendor nurseries', implode(' ', $plan->warnings));
    }

    public function test_a_targeted_wipe_leaves_unrelated_orphans_alone(): void
    {
        // Only a full wipe claims orphans — removing two named vendors must not quietly take
        // unrelated stale records with it.
        if (!\Illuminate\Support\Facades\Schema::hasTable('nursery_nurseries')) {
            $this->markTestSkipped('V2 nursery tables are not in this stub schema.');
        }
        DB::table('nursery_nurseries')->insert([
            ['id' => 19, 'uuid' => 'u-orphan', 'name' => 'Gone Nursery', 'slug' => 'gone-nursery', 'legacy_id' => 999, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $plan = (new VendorsCleaner())->plan(['ids' => [33]]);
        $step = collect($plan->steps)->firstWhere('table', 'nursery_nurseries');

        $this->assertTrue($step === null || !in_array(19, $step['ids'], true), 'a targeted wipe must not sweep unrelated orphans');
    }

    public function test_a_shop_that_owns_products_is_refused(): void
    {
        DB::table('products')->where('id', 2)->update(['shop_id' => 33]);
        $plan = (new VendorsCleaner())->plan(['ids' => [33]]);

        $shopStep = collect($plan->steps)->firstWhere('table', 'shops');
        $this->assertNull($shopStep, 'a product-owning shop must not be deletable');
        $this->assertStringContainsString('still own catalog products', implode(' ', $plan->warnings));
    }

    public function test_vendor_cleanup_keeps_proposed_products_and_detaches_staff(): void
    {
        (new CleanupRunner())->execute(new VendorsCleaner(), ['ids' => [33]], [], null);

        $this->assertSame(0, DB::table('shops')->where('id', 33)->count());
        $this->assertSame(0, DB::table('vendor_product_prices')->count());
        // The proposed product survives, with its proposer cleared.
        $this->assertSame(2, DB::table('products')->count());
        $this->assertNull(DB::table('products')->where('id', 1)->value('proposed_by_shop_id'));
        // Staff are detached, not deleted.
        $this->assertSame(3, DB::table('users')->count());
        $this->assertNull(DB::table('users')->where('id', 3)->value('shop_id'));
    }

    public function test_protected_tables_are_refused_even_if_a_plan_names_them(): void
    {
        $plan = new CleanupPlan('rogue');
        $plan->step('products', [1, 2]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('protected catalog/config tables');
        (new CleanupRunner())->assertPlanIsSafe($plan);
    }

    public function test_snapshot_makes_a_hard_delete_restorable(): void
    {
        $runner = new CleanupRunner();
        $res = $runner->execute(new VendorInventoryCleaner(), ['shop_ids' => [33]], [], null);
        $this->assertSame(0, DB::table('vendor_product_prices')->count());

        $runner->restore($res['run_id']);

        $this->assertSame(1, DB::table('vendor_product_prices')->count());
        $this->assertSame(1, DB::table('vendor_inventory_reviews')->count());
        $this->assertSame('restored', DB::table('test_data_cleanup_runs')->where('id', $res['run_id'])->value('status'));
    }

    public function test_running_twice_is_a_safe_no_op(): void
    {
        $runner = new CleanupRunner();
        $runner->execute(new OrdersCleaner(), ['ids' => [100]], [], null);
        $second = $runner->execute(new OrdersCleaner(), ['ids' => [100]], [], null);

        $this->assertSame(0, $second['total_rows']);
        $this->assertSame('completed', DB::table('test_data_cleanup_runs')->orderByDesc('id')->value('status'));
    }

    public function test_an_unscoped_request_plans_nothing(): void
    {
        $this->assertTrue((new OrdersCleaner())->plan([])->isEmpty(), 'a wipe must be explicit');
        $this->assertTrue((new UsersCleaner())->plan([])->isEmpty());
        $this->assertTrue((new VendorsCleaner())->plan([])->isEmpty());
    }

    public function test_users_cleanup_refuses_shop_owners_and_takes_their_orders(): void
    {
        $plan = (new UsersCleaner())->plan(['ids' => [2, 3]]);
        $userStep = collect($plan->steps)->firstWhere('table', 'users');

        $this->assertNotContains(2, $userStep['ids'], 'shop owner must be excluded');
        $this->assertContains(3, $userStep['ids']);
        $this->assertNotNull(collect($plan->steps)->firstWhere('table', 'orders'), 'their orders come too');
        $this->assertNotNull(collect($plan->steps)->firstWhere('table', 'address'), 'RESTRICT blocker cleared');
    }

    /** Production ceremony: the phrase, the acknowledgement and a backup are all required. */
    public function test_production_requires_full_ceremony(): void
    {
        putenv('TESTDATA_ENV=production');
        $runner = new CleanupRunner();
        $this->assertTrue($runner->isProduction());

        foreach ([
            [[], 'confirmation phrase'],
            [['confirm_phrase' => CleanupRunner::CONFIRM_PHRASE], 'acknowledgement'],
            [['confirm_phrase' => CleanupRunner::CONFIRM_PHRASE, 'acknowledge' => true], 'backup'],
        ] as [$opts, $expected]) {
            try {
                $runner->execute(new VendorInventoryCleaner(), ['shop_ids' => [33]], $opts, null);
                $this->fail("expected refusal mentioning '{$expected}'");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($expected, strtolower($e->getMessage()));
            }
        }
        $this->assertSame(1, DB::table('vendor_product_prices')->count(), 'nothing deleted while refused');
        putenv('TESTDATA_ENV=staging');
    }

    /** The Launch Guard: a paid order means the system is live and bulk cleanup is refused. */
    public function test_launch_guard_blocks_once_a_payment_exists(): void
    {
        putenv('TESTDATA_ENV=production');
        $guard = (new CleanupRunner())->launchGuard();

        $this->assertTrue($guard['blocked']);
        $this->assertStringContainsString('successful payment', implode(' ', $guard['reasons']));

        DB::table('orders')->update(['payment_status' => 'payment-pending']);
        $this->assertFalse((new CleanupRunner())->launchGuard()['blocked']);
        putenv('TESTDATA_ENV=staging');
    }

    /** An unset TESTDATA_ENV must mean maximum ceremony, never less. */
    public function test_environment_defaults_to_production_when_unset(): void
    {
        putenv('TESTDATA_ENV');
        $this->assertSame('production', (new CleanupRunner())->environment());
        putenv('TESTDATA_ENV=staging');
    }
}
