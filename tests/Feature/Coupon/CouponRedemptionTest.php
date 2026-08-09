<?php

declare(strict_types=1);

namespace Tests\Feature\Coupon;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Repositories\CouponRepository;
use Marvel\Exceptions\MarvelBadRequestException;
use Tests\TestCase;

/**
 * Coupon single-use / usage-limited redemption — the acceptance criterion: a coupon with
 * usage_limit = 1 can be redeemed at most once no matter how many attempts arrive.
 *
 * True OS-level parallelism isn't available in PHPUnit, so this proves the enforcement LOGIC
 * that CouponRepository::consume() runs while holding the coupon row lock: over-consumption is
 * rejected, redemption is idempotent per order, per-user caps hold, and a release restores a
 * slot. On MySQL the lockForUpdate inside consume() serialises concurrent transactions so this
 * same logic runs one-at-a-time across processes — see the docblock on consume().
 */
final class CouponRedemptionTest extends TestCase
{
    private CouponRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');

        Schema::create('coupons', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('code');
            $t->string('type')->default('fixed');
            $t->float('amount')->default(0);
            $t->float('minimum_cart_amount')->default(0);
            $t->string('active_from')->nullable();
            $t->string('expire_at')->nullable();
            $t->unsignedInteger('usage_limit')->nullable();
            $t->unsignedInteger('usage_limit_per_user')->nullable();
            $t->unsignedInteger('times_used')->default(0);
            $t->boolean('target')->default(false);
            $t->boolean('is_approve')->default(true);
            $t->string('language')->default('en');
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });

        Schema::create('coupon_usages', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('coupon_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('order_id');
            $t->timestamps();
            $t->unique(['coupon_id', 'order_id']);
            $t->index(['coupon_id', 'user_id']);
        });

        $this->repo = app(CouponRepository::class);
    }

    private function makeCoupon(array $attrs = []): Coupon
    {
        return Coupon::create(array_merge([
            'code'        => 'SAVE',
            'type'        => 'fixed',
            'amount'      => 100,
            'active_from' => now()->subDay()->toDateTimeString(),
            'expire_at'   => now()->addDay()->toDateTimeString(),
            'is_approve'  => true,
        ], $attrs));
    }

    /** THE acceptance criterion: usage_limit=1, many attempts → exactly one redemption. */
    public function test_single_use_coupon_is_consumed_at_most_once(): void
    {
        $coupon = $this->makeCoupon(['usage_limit' => 1]);

        $successes = 0;
        for ($orderId = 1; $orderId <= 100; $orderId++) {
            try {
                $this->repo->consume($coupon, $orderId, /* userId */ $orderId);
                $successes++;
            } catch (MarvelBadRequestException $e) {
                // exhausted — expected for all but the first
            }
        }

        $this->assertSame(1, $successes, 'exactly one of 100 redemption attempts may succeed');
        $this->assertSame(1, (int) $coupon->fresh()->times_used, 'times_used must never exceed the limit');
        $this->assertSame(1, DB::table('coupon_usages')->where('coupon_id', $coupon->id)->count());
    }

    /** Re-consuming for the SAME order (a retry / duplicate submit) is a no-op. */
    public function test_consume_is_idempotent_per_order(): void
    {
        $coupon = $this->makeCoupon(['usage_limit' => 5]);

        $this->repo->consume($coupon, 42, 7);
        $this->repo->consume($coupon, 42, 7); // retry same order
        $this->repo->consume($coupon, 42, 7);

        $this->assertSame(1, (int) $coupon->fresh()->times_used, 'a retried order consumes once');
        $this->assertSame(1, DB::table('coupon_usages')->where('order_id', 42)->count());
    }

    public function test_per_user_limit_is_enforced_independently_of_the_global_limit(): void
    {
        $coupon = $this->makeCoupon(['usage_limit_per_user' => 1]); // global unlimited

        $this->repo->consume($coupon, 1, 5); // user 5, first — ok
        $this->expectThrown(fn () => $this->repo->consume($coupon, 2, 5), MarvelBadRequestException::class); // user 5, second — blocked
        $this->repo->consume($coupon, 3, 6); // a DIFFERENT user — ok

        $this->assertSame(2, (int) $coupon->fresh()->times_used);
    }

    /** A failed/cancelled order gives its slot back; unlimited stays unlimited. */
    public function test_release_restores_a_slot(): void
    {
        $coupon = $this->makeCoupon(['usage_limit' => 1]);

        $this->repo->consume($coupon, 1, 5);
        $this->expectThrown(fn () => $this->repo->consume($coupon, 2, 6), MarvelBadRequestException::class);

        $this->repo->release($coupon->id, 1); // order 1's payment failed
        $this->assertSame(0, (int) $coupon->fresh()->times_used);
        $this->assertSame(0, DB::table('coupon_usages')->where('coupon_id', $coupon->id)->count());

        // The slot is now free again.
        $this->repo->consume($coupon, 2, 6);
        $this->assertSame(1, (int) $coupon->fresh()->times_used);
    }

    public function test_release_never_drives_the_counter_negative(): void
    {
        $coupon = $this->makeCoupon(['usage_limit' => 1]);
        $this->repo->consume($coupon, 1, 5);

        $this->repo->release($coupon->id, 1);
        $this->repo->release($coupon->id, 1); // double release
        $this->repo->release($coupon->id, 999); // unknown order

        $this->assertSame(0, (int) $coupon->fresh()->times_used);
    }

    /** A null usage_limit is the legacy unlimited behaviour — nothing is capped. */
    public function test_unlimited_coupon_never_blocks(): void
    {
        $coupon = $this->makeCoupon(); // no limits

        for ($orderId = 1; $orderId <= 50; $orderId++) {
            $this->repo->consume($coupon, $orderId, 5);
        }

        $this->assertSame(50, (int) $coupon->fresh()->times_used);
    }

    private function expectThrown(callable $fn, string $exception): void
    {
        try {
            $fn();
            $this->fail("expected {$exception} was not thrown");
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e);
        }
    }
}
