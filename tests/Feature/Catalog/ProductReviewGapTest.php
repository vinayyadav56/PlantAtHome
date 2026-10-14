<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Product;
use Marvel\Database\Repositories\ProductRepository;
use Marvel\Events\ProductReviewApproved;
use Marvel\Events\ProductReviewRejected;
use Tests\TestCase;

/**
 * Who may set which status when a product is edited (ProductRepository::statusForUpdate).
 *
 * Production runs with product review on. There, a vendor who edited their own proposal
 * published it (any status but publish/unpublish/draft became publish), skipping the admin's
 * review; with review off they could simply send "publish". And the admin who owns the master
 * shop (its owner is the first super admin) went through the vendor rules: "Published" on an
 * under-review product became a draft, and an admin save without a status rejected the product.
 *
 * Fixture: master shop 1 owned by admin 99; vendor 50 owns shop 7, which proposed product 1.
 */
final class ProductReviewGapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default'            => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');

        Schema::create('products', function (Blueprint $t) {
            $t->boolean('is_available_product')->default(true);
            $t->boolean('listing_enabled')->default(true);
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('slug')->nullable();
            $t->string('language')->default('en');
            $t->string('status')->default('under_review');
            $t->unsignedBigInteger('shop_id')->nullable();
            $t->unsignedBigInteger('proposed_by_shop_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('shops', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->string('name')->nullable();
            $t->string('slug')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        DB::table('users')->insert([['id' => 99, 'name' => 'Admin'], ['id' => 50, 'name' => 'Vendor'], ['id' => 51, 'name' => 'Other vendor']]);
        DB::table('shops')->insert([['id' => 1, 'owner_id' => 99, 'slug' => 'plantathome'], ['id' => 7, 'owner_id' => 50, 'slug' => 'vendor'], ['id' => 8, 'owner_id' => 51, 'slug' => 'other']]);
        DB::table('products')->insert(['id' => 1, 'name' => 'Proposed Palm', 'status' => 'under_review', 'shop_id' => 1, 'proposed_by_shop_id' => 7]);
    }

    private function user(int $id, bool $admin)
    {
        $user = \Mockery::mock();
        $user->id = $id;
        $user->shouldReceive('hasPermissionTo')->andReturnUsing(fn ($p) => $admin ? true : (string) $p === 'store_owner');

        return $user;
    }

    private function storedStatus(array $input, $user, bool $review, string $current = 'under_review'): ?string
    {
        DB::table('products')->where('id', 1)->update(['status' => $current]);
        $request = Request::create('/api/products/1', 'PUT', $input);
        $request->setUserResolver(fn () => $user);

        return app(ProductRepository::class)->statusForUpdate($request, Product::query()->findOrFail(1), (object) ['options' => ['isProductReview' => $review]], false);
    }

    public function test_a_vendor_never_publishes_their_own_proposal(): void
    {
        $vendor = $this->user(50, false);
        foreach ([true, false] as $review) {
            $this->assertSame('under_review', $this->storedStatus(['status' => 'publish'], $vendor, $review), 'review ' . ($review ? 'on' : 'off'));
            $this->assertSame('under_review', $this->storedStatus(['status' => 'under_review'], $vendor, $review));
            $this->assertSame('under_review', $this->storedStatus(['status' => 'publish'], $vendor, $review, 'rejected'), 'a rejected proposal goes back to review');
            $this->assertSame('draft', $this->storedStatus(['status' => 'draft'], $vendor, $review));
        }
        $this->assertSame('under_review', $this->storedStatus([], $vendor, true), 'no status: back to review, never publish');
    }

    public function test_the_admin_who_owns_the_master_shop_decides_like_any_admin(): void
    {
        $admin = $this->user(99, true);

        $this->assertSame('publish', $this->storedStatus(['status' => 'publish'], $admin, true), 'was turned into a draft');
        $this->assertSame('draft', $this->storedStatus(['status' => 'draft'], $admin, true, 'publish'));
        $this->assertSame('unpublish', $this->storedStatus(['status' => 'unpublish'], $admin, true, 'publish'));
        $this->assertSame('under_review', $this->storedStatus([], $admin, true), 'no status keeps it (it used to reject)');
        $this->assertSame('publish', $this->storedStatus(['status' => 'publish'], $admin, false));
        $this->assertNull($this->storedStatus([], $admin, false), 'review off: no status leaves it as it is');
    }

    public function test_approval_and_rejection_by_an_admin_tell_the_proposer(): void
    {
        Event::fake([ProductReviewApproved::class, ProductReviewRejected::class]);
        $request = Request::create('/api/products/1', 'PUT', ['status' => 'approved']);
        $request->setUserResolver(fn () => $this->user(98, true));   // an admin who does not own the master shop
        $product = Product::query()->findOrFail(1);
        $repository = app(ProductRepository::class);

        $this->assertSame('publish', $repository->statusForUpdate($request, $product, (object) ['options' => ['isProductReview' => true]]));
        Event::assertDispatched(ProductReviewApproved::class);

        $request->merge(['status' => 'rejected']);
        $this->assertSame('rejected', $repository->statusForUpdate($request, $product, (object) ['options' => ['isProductReview' => true]]));
        Event::assertDispatched(ProductReviewRejected::class);

        $request->merge(['status' => 'draft']);
        $this->assertSame('draft', $repository->statusForUpdate($request, $product, (object) ['options' => ['isProductReview' => true]]), 'was turned into rejected');
    }

    public function test_another_vendor_gets_no_say_over_a_proposal(): void
    {
        $this->assertSame('rejected', $this->storedStatus(['status' => 'publish'], $this->user(51, false), true), 'unchanged rule: not theirs, not an admin');
    }
}
