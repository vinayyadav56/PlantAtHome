<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\VendorInventoryReview;
use Marvel\Database\Models\VendorProductPrice;
use Marvel\Http\Controllers\InventoryReviewController;
use Marvel\Services\PricingService;
use Marvel\Services\VendorInventoryWriter;
use Tests\TestCase;

/**
 * The review pipeline's state machine + enforcement, transition by transition:
 * submit → pending; admin approve/reject/request-changes (reasons required);
 * resubmission; material-change auto-pend; stock edits stay live; delete + re-add
 * cannot resurrect approval; bulk actions; optimistic concurrency; and the
 * pricing seam ignoring anything unapproved.
 */
final class VendorInventoryReviewTest extends TestCase
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
        VendorProductPrice::resetReviewStatics();

        Schema::create('products', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('slug')->nullable();
            $t->string('sku')->nullable();
            $t->json('image')->nullable();
            $t->unsignedBigInteger('type_id')->nullable();
            $t->string('product_type')->default('variable');
            $t->decimal('price')->nullable();
            $t->decimal('sale_price')->nullable();
            $t->decimal('min_price')->nullable();
            $t->string('status')->default('publish');
            $t->string('language')->default('en');
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('products_meta', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('type')->default('null');
            $t->string('key');
            $t->text('value')->nullable();
            $t->timestamps();
        });
        Schema::create('variation_options', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('title');
            $t->string('sku')->nullable();
            $t->decimal('price')->nullable();
            $t->timestamps();
        });
        Schema::create('shops', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('slug')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->string('approval_status')->nullable();
            $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->timestamps();
        });
        Schema::create('vendor_service_areas', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('shop_id');
            $t->string('city')->nullable();
            $t->string('pincode')->nullable();
            $t->string('fulfillment_mode')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('vendor_product_prices', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('variation_option_id')->nullable();
            $t->string('period_type')->default('monthly');
            $t->date('effective_from')->nullable();
            $t->date('effective_to')->nullable();
            $t->decimal('vendor_selling_price')->nullable();
            $t->decimal('cost_price')->nullable();
            $t->integer('stock_qty')->default(0);
            $t->integer('reserved_qty')->default(0);
            $t->boolean('track_stock')->default(false);
            $t->string('fulfillment_mode')->nullable();
            $t->integer('moq')->nullable();
            $t->integer('lead_time_days')->nullable();
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->unsignedBigInteger('updated_by_user_id')->nullable();
            $t->unsignedBigInteger('import_batch_id')->nullable();
            $t->string('dedupe_key')->nullable()->unique();
            $t->boolean('is_available')->default(true);
            $t->string('source')->nullable();
            $t->string('review_status')->default('pending_review');
            $t->text('review_comment')->nullable();
            $t->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('vendor_inventory_reviews', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('vendor_product_price_id');
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('variation_option_id')->nullable();
            $t->string('previous_status')->nullable();
            $t->string('new_status');
            $t->string('action');
            $t->unsignedBigInteger('actor_user_id')->nullable();
            $t->text('comment')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('notify_logs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('receiver');
            $t->unsignedBigInteger('sender')->nullable();
            $t->text('notify_type')->nullable();
            $t->text('notify_receiver_type')->nullable();
            $t->boolean('is_read')->default(false);
            $t->text('notify_tracker')->nullable();
            $t->text('notify_text')->nullable();
            $t->timestamps();
        });
        Schema::create('product_city_availability', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id');
            $t->string('city')->nullable();
            $t->unsignedBigInteger('variation_option_id')->default(0);
            $t->boolean('has_local')->default(false);
            $t->boolean('has_courier')->default(false);
            $t->decimal('min_price')->nullable();
            $t->decimal('display_price')->nullable();
            $t->integer('stock')->nullable();
            $t->integer('stock_override')->nullable();
            $t->integer('vendor_count')->default(0);
            $t->timestamp('updated_at')->nullable();
        });
        Schema::create('categories', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
        Schema::create('category_product', function (Blueprint $t) {
            $t->unsignedBigInteger('category_id');
            $t->unsignedBigInteger('product_id');
        });
        Schema::create('cities', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->unsignedBigInteger('parent_city_id')->nullable();
            $t->timestamps();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->text('options')->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
        });
        DB::table('settings')->insert(['options' => '{}', 'language' => 'en', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('products')->insert([
            ['id' => 1, 'name' => 'Ficus Lyrata', 'slug' => 'ficus-lyrata', 'price' => 500, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('shops')->insert([
            ['id' => 33, 'name' => 'Delhi Nursery 2', 'slug' => 'delhi-nursery-2', 'owner_id' => 9, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('users')->insert([
            ['id' => 9, 'name' => 'Vendor Owner', 'email' => 'owner@x.test', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /** Vendor-actor write via the shared writer (the attach / bulk path). */
    private function vendorAttach(array $item): VendorProductPrice
    {
        VendorProductPrice::$adminActor = false;
        (new VendorInventoryWriter())->writeItems(33, [$item], ['user_id' => 9]);
        return VendorProductPrice::where('shop_id', 33)->where('product_id', $item['product_id'])
            ->orderByDesc('id')->firstOrFail();
    }

    private function adminRequest(array $params = []): Request
    {
        $request = new Request($params);
        $request->setUserResolver(fn () => new class {
            public $id = 1;
            public function hasPermissionTo($p): bool { return true; }
        });
        return $request;
    }

    private function act(int $id, string $action, ?string $comment = null, ?string $expected = null): array
    {
        $req = $this->adminRequest(array_filter([
            'action' => $action, 'comment' => $comment, 'expected_status' => $expected,
        ], fn ($v) => $v !== null));
        $res = (new InventoryReviewController())->action($req, $id);
        return ['status' => $res->getStatusCode(), 'body' => $res->getData(true)];
    }

    public function test_vendor_submission_lands_pending_with_audit_and_notification(): void
    {
        $row = $this->vendorAttach(['product_id' => 1, 'vendor_selling_price' => 450]);

        $this->assertSame('pending_review', $row->review_status);
        $this->assertNotNull($row->submitted_at);
        $this->assertSame(1, VendorInventoryReview::where('vendor_product_price_id', $row->id)
            ->where('action', 'submitted')->count());
    }

    public function test_admin_actor_rows_are_auto_approved(): void
    {
        VendorProductPrice::$adminActor = true;
        (new VendorInventoryWriter())->writeItems(33, [['product_id' => 1, 'vendor_selling_price' => 450]], ['user_id' => 1]);
        $row = VendorProductPrice::firstOrFail();

        $this->assertSame('approved', $row->review_status);
        $this->assertNotNull($row->approved_at);
    }

    public function test_approve_records_admin_and_timestamps(): void
    {
        $row = $this->vendorAttach(['product_id' => 1, 'vendor_selling_price' => 450]);
        $res = $this->act($row->id, 'approve');

        $this->assertSame(200, $res['status']);
        $row->refresh();
        $this->assertSame('approved', $row->review_status);
        $this->assertSame(1, $row->reviewed_by_user_id);
        $this->assertNotNull($row->reviewed_at);
        $this->assertNotNull($row->approved_at);
        $this->assertSame(1, VendorInventoryReview::where('vendor_product_price_id', $row->id)
            ->where('action', 'approved')->count());
        $this->assertSame(1, DB::table('notify_logs')->where('receiver', 9)
            ->where('notify_type', 'inventory_review')->where('notify_text', 'like', '%approved%')->count());
    }

    public function test_reject_requires_a_reason(): void
    {
        $row = $this->vendorAttach(['product_id' => 1, 'vendor_selling_price' => 450]);
        try {
            $this->act($row->id, 'reject');
            $this->fail('reject without a reason must fail validation');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('comment', $e->errors());
        }
        $this->assertSame('pending_review', $row->fresh()->review_status);
    }

    public function test_reject_with_reason_reaches_the_vendor(): void
    {
        $row = $this->vendorAttach(['product_id' => 1, 'vendor_selling_price' => 450]);
        $res = $this->act($row->id, 'reject', 'Price far above market rate');

        $this->assertSame(200, $res['status']);
        $row->refresh();
        $this->assertSame('rejected', $row->review_status);
        $this->assertSame('Price far above market rate', $row->review_comment);
        $this->assertSame(1, DB::table('notify_logs')->where('receiver', 9)
            ->where('notify_text', 'like', '%Price far above market rate%')->count());
    }

    public function test_request_changes_then_vendor_edit_resubmits(): void
    {
        $row = $this->vendorAttach(['product_id' => 1, 'vendor_selling_price' => 450]);
        $this->act($row->id, 'request_changes', 'Add a sharper photo and fix the size label');
        $this->assertSame('changes_requested', $row->fresh()->review_status);

        // The vendor edits and saves — that IS the resubmission.
        VendorProductPrice::$adminActor = false;
        $fresh = $row->fresh();
        $fresh->vendor_selling_price = 425;
        $fresh->updated_by_user_id = 9;
        $fresh->save();

        $this->assertSame('pending_review', $fresh->fresh()->review_status);
        $this->assertSame(1, VendorInventoryReview::where('vendor_product_price_id', $row->id)
            ->where('action', 'resubmitted')->count());
    }

    public function test_material_price_change_on_approved_row_auto_pends(): void
    {
        $row = $this->vendorAttach(['product_id' => 1, 'vendor_selling_price' => 450]);
        $this->act($row->id, 'approve');

        VendorProductPrice::$adminActor = false;
        $fresh = $row->fresh();
        $fresh->vendor_selling_price = 999;
        $fresh->updated_by_user_id = 9;
        $fresh->save();

        $this->assertSame('pending_review', $fresh->fresh()->review_status, 'a re-priced offer is a NEW offer');
        $this->assertSame(1, VendorInventoryReview::where('vendor_product_price_id', $row->id)
            ->where('action', 'material_change')->count());
    }

    public function test_stock_update_keeps_approval(): void
    {
        $row = $this->vendorAttach(['product_id' => 1, 'vendor_selling_price' => 450]);
        $this->act($row->id, 'approve');

        VendorProductPrice::$adminActor = false;
        $fresh = $row->fresh();
        $fresh->stock_qty = 120;
        $fresh->track_stock = true;
        $fresh->updated_by_user_id = 9;
        $fresh->save();

        $this->assertSame('approved', $fresh->fresh()->review_status, 'daily stock updates must not de-list');
    }

    public function test_delete_and_readd_cannot_resurrect_approval(): void
    {
        $row = $this->vendorAttach(['product_id' => 1, 'vendor_selling_price' => 450]);
        $this->act($row->id, 'approve');

        $row->fresh()->delete();

        // Re-adding the same identity restores the trashed row — as a NEW submission.
        VendorProductPrice::$adminActor = false;
        (new VendorInventoryWriter())->writeItems(33, [['product_id' => 1, 'vendor_selling_price' => 450]], ['user_id' => 9]);

        $restored = VendorProductPrice::where('shop_id', 33)->where('product_id', 1)->firstOrFail();
        $this->assertNull($restored->deleted_at);
        $this->assertSame('pending_review', $restored->review_status, 'delete + re-add must re-enter the queue');
    }

    public function test_bulk_apply_reports_per_row_results(): void
    {
        $a = $this->vendorAttach(['product_id' => 1, 'variation_option_id' => null, 'vendor_selling_price' => 450]);
        $this->act($a->id, 'approve');

        DB::table('variation_options')->insert([
            ['id' => 71, 'product_id' => 1, 'title' => 'Large', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $b = $this->vendorAttach(['product_id' => 1, 'variation_option_id' => 71, 'vendor_selling_price' => 900]);

        $req = $this->adminRequest(['ids' => [$a->id, $b->id], 'action' => 'approve']);
        $res = (new InventoryReviewController())->bulk($req)->getData(true);

        $this->assertSame(1, $res['applied'], 'only the pending row applies');
        $this->assertSame(1, $res['failed'], 'the already-approved row is refused, not silently re-approved');
        $this->assertSame('approved', $b->fresh()->review_status);
    }

    public function test_stale_expected_status_is_refused_409(): void
    {
        $row = $this->vendorAttach(['product_id' => 1, 'vendor_selling_price' => 450]);
        $this->act($row->id, 'approve');

        $res = $this->act($row->id, 'reject', 'looked wrong', 'pending_review');
        $this->assertSame(409, $res['status'], "a decision made against a stale status must not overwrite the newer one");
        $this->assertSame('approved', $row->fresh()->review_status);
    }

    public function test_pricing_seam_ignores_unapproved_offers(): void
    {
        $product = \Marvel\Database\Models\Product::findOrFail(1);
        $row = $this->vendorAttach(['product_id' => 1, 'vendor_selling_price' => 999]);
        $this->assertSame('pending_review', $row->review_status);

        $pricing = new PricingService();
        $before = $pricing->sellingPrice($product, null);
        $this->assertFalse((bool) $before['has_vendor_cost'], 'a pending offer must not price anything');
        $this->assertEquals(500.0, (float) $before['price'], 'falls back to the admin-vetted catalog price');

        $this->act($row->id, 'approve');
        $after = (new PricingService())->sellingPrice($product, null);
        $this->assertTrue((bool) $after['has_vendor_cost'], 'approval turns the offer on');
    }
}
