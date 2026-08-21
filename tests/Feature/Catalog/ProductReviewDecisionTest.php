<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Marvel\Events\ProductReviewApproved;
use Marvel\Events\ProductReviewRejected;
use Marvel\Http\Controllers\ProductController;
use Tests\TestCase;

/**
 * The admin decision on a vendor-proposed product.
 *
 * A vendor may propose a plant the catalogue is missing; it lands in `under_review`. Two
 * things were missing from the decision made through the review queue: the rejection had
 * nowhere to record WHY (so the vendor re-submitted the same thing), and the queue endpoint
 * fired no events at all, so the proposer was never told either way.
 */
final class ProductReviewDecisionTest extends TestCase
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

        Schema::create('products', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('slug')->nullable();
            $t->string('language')->default('en');
            $t->string('status')->default('under_review');
            $t->text('review_note')->nullable();
            $t->unsignedBigInteger('shop_id')->nullable();
            $t->unsignedBigInteger('proposed_by_shop_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        // Product is kodeine Metable: saving consults its meta table, so it must exist even
        // though nothing here uses product meta.
        Schema::create('products_meta', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('type')->nullable();
            $t->string('key')->nullable();
            $t->text('value')->nullable();
            $t->timestamps();
        });
        DB::table('products')->insert([
            'id' => 1, 'name' => 'Proposed Palm', 'status' => 'under_review',
            'shop_id' => 1, 'proposed_by_shop_id' => 7,
        ]);
    }

    /**
     * updateStatus is super-admin gated; these tests target the DECISION, not the gate, so
     * the resolver goes on the request the controller actually reads.
     */
    private function decide(array $payload)
    {
        $request = Request::create('/api/update-product-status', 'POST', $payload);
        $user = \Mockery::mock();
        $user->shouldReceive('hasPermissionTo')->andReturn(true);
        $request->setUserResolver(fn () => $user);

        return app(ProductController::class)->updateStatus($request);
    }

    public function test_rejecting_without_a_reason_is_refused(): void
    {
        // A rejection the vendor cannot act on just comes back unchanged.
        $this->expectException(ValidationException::class);
        $this->decide(['id' => 1, 'status' => 'rejected']);
    }

    public function test_a_rejection_reason_is_stored_and_the_proposer_is_notified(): void
    {
        Event::fake([ProductReviewRejected::class]);

        $this->decide(['id' => 1, 'status' => 'rejected', 'note' => 'Duplicate of Areca Palm.']);

        $row = DB::table('products')->find(1);
        $this->assertSame('rejected', $row->status);
        $this->assertSame('Duplicate of Areca Palm.', $row->review_note);
        Event::assertDispatched(ProductReviewRejected::class);
    }

    public function test_publishing_clears_a_stale_reason_and_notifies(): void
    {
        // A note explaining a rejection that no longer applies reads as a live objection.
        DB::table('products')->where('id', 1)->update(['status' => 'rejected', 'review_note' => 'Needs a better photo.']);
        Event::fake([ProductReviewApproved::class]);

        $this->decide(['id' => 1, 'status' => 'publish']);

        $row = DB::table('products')->find(1);
        $this->assertSame('publish', $row->status);
        $this->assertNull($row->review_note);
        Event::assertDispatched(ProductReviewApproved::class);
    }

    public function test_approval_notifies_the_proposing_vendor_not_the_master_shop_owner(): void
    {
        // Post-cutover every product's shop IS the master shop, so notifying shop->owner told
        // the admin about their own decision and the proposer heard nothing.
        $product = new \Marvel\Database\Models\Product(['id' => 1]);
        $proposer = \Mockery::mock();
        $proposer->shouldReceive('notify')->once();
        $masterOwner = \Mockery::mock();
        $masterOwner->shouldReceive('notify')->never();

        $product->setRelation('proposedByShop', (object) ['owner' => $proposer]);
        $product->setRelation('shop', (object) ['owner' => $masterOwner]);

        (new \Marvel\Listeners\ProductReviewApprovedListener())
            ->handle(new ProductReviewApproved($product));
    }
}
