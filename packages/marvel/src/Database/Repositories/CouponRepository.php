<?php


namespace Marvel\Database\Repositories;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponUsage;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\Shop;
use Marvel\Enums\CouponType;
use Marvel\Enums\Permission;
use Marvel\Exceptions\MarvelBadRequestException;

class CouponRepository extends BaseRepository
{

    /**
     * @var array
     */
    protected $fieldSearchable = [
        'code'        => 'like',
        'shop_id',
        'language',

    ];

    protected $dataArray = [
        'code',
        'language',
        'description',
        'image',
        'type',
        'amount',
        'minimum_cart_amount',
        'active_from',
        'expire_at',
        'target',
        'is_approve',
        'user_id',
        'shop_id',
        'usage_limit',
        'usage_limit_per_user',
    ];

    public function getDataArray(): array
    {
        return $this->dataArray;
    }

    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
            //
        }
    }
    /**
     * Configure the Model
     **/
    public function model()
    {
        return Coupon::class;
    }

    /**
     * storeCoupon
     *
     * @param  mixed $request
     * @return mixed
     */
    public function storeCoupon(Request $request)
    {
        try {
            $data = $request->only($this->dataArray);
            $data['user_id'] = $request->user()->id;
            $data['is_approve'] = $request->user()->hasPermissionTo(Permission::SUPER_ADMIN);
            return $this->create($data);
        } catch (Exception $th) {
            throw new MarvelBadRequestException(COULD_NOT_CREATE_THE_RESOURCE);
        }
    }
    /**
     * Consume one redemption of a coupon for an order — the AUTHORITATIVE, concurrency-safe
     * gate. MUST run inside the order-creation DB::transaction (OrderController::store wraps
     * storeOrder in one), so the row lock below is held until the order commits.
     *
     * How it stays correct under 100 concurrent redemptions of a usage_limit=1 coupon:
     *   1. lockForUpdate() serialises every concurrent consumer of THIS coupon row — the
     *      second txn blocks until the first commits or rolls back.
     *   2. Once it holds the lock it reads the true times_used / per-user count and rejects
     *      if the limit is already reached. Exactly one caller can be the one that takes the
     *      last slot; the rest throw 422 and their whole order transaction rolls back.
     *   3. The usage row is idempotent on (coupon_id, order_id), so a retried checkout for
     *      the same order never double-consumes, and times_used is only incremented when the
     *      row is genuinely new.
     *
     * A null usage_limit / usage_limit_per_user means "unlimited" — the pre-2026-08 behaviour,
     * so unlimited coupons still just record a usage row and skip the caps.
     *
     * @throws MarvelBadRequestException when the coupon is exhausted.
     */
    public function consume(Coupon $coupon, int $orderId, ?int $userId): void
    {
        // Re-read under a row lock. Every concurrent order using this coupon queues here.
        $locked = Coupon::whereKey($coupon->id)->lockForUpdate()->first();
        if (!$locked) {
            return; // coupon vanished mid-flight; nothing to consume
        }

        // Already recorded for this order? Idempotent no-op (retry / duplicate submit).
        if (CouponUsage::where('coupon_id', $locked->id)->where('order_id', $orderId)->exists()) {
            return;
        }

        if ($locked->usage_limit !== null && (int) $locked->times_used >= (int) $locked->usage_limit) {
            throw new MarvelBadRequestException('This coupon has reached its usage limit.');
        }
        if ($locked->usage_limit_per_user !== null && $userId) {
            $usedByUser = CouponUsage::where('coupon_id', $locked->id)->where('user_id', $userId)->count();
            if ($usedByUser >= (int) $locked->usage_limit_per_user) {
                throw new MarvelBadRequestException('You have already used this coupon the maximum number of times.');
            }
        }

        CouponUsage::create([
            'coupon_id' => $locked->id,
            'user_id'   => $userId,
            'order_id'  => $orderId,
        ]);
        // Atomic increment on the locked row.
        $locked->increment('times_used');
    }

    /**
     * Give a coupon redemption back — called when a placed order is cancelled or its payment
     * fails, so a failed checkout does not permanently burn a single-use coupon. Deletes the
     * order's usage row (if any) and decrements times_used by that amount. Idempotent: a
     * second release for the same order finds no row and does nothing, and times_used is
     * floored at 0.
     */
    public function release(?int $couponId, int $orderId): void
    {
        if (!$couponId) {
            return;
        }
        DB::transaction(function () use ($couponId, $orderId) {
            $deleted = CouponUsage::where('coupon_id', $couponId)->where('order_id', $orderId)->delete();
            if ($deleted > 0) {
                // Floor at 0 so a double-release can never drive the counter negative.
                Coupon::whereKey($couponId)->where('times_used', '>=', $deleted)->decrement('times_used', $deleted);
            }
        });
    }

    public function verifyCoupon(Request $request)
    {
        $code = $request->code;
        $sub_total = $request->sub_total;
        $item = $request->item ?? null;
        try {
            $coupon = $this->findOneByFieldOrFail('code', $code);
            $settings = Settings::getData();
            $is_satisfy = $sub_total >= $coupon->minimum_cart_amount;
            $is_freeShipping = $settings['options']['freeShipping'];
            $freeShippingAmount = $settings['options']['freeShippingAmount'];
            $couponShopId = $coupon->shop_id;
            $useFreeShipping = $is_freeShipping && $freeShippingAmount <= $sub_total;

            if (!$coupon->is_approve || (empty($request->user()) && $coupon->target)) {
                return ["is_valid" => false, "message" => $coupon->is_approve ? THIS_COUPON_CODE_IS_ONLY_FOR_VERIFIED_USERS : THIS_COUPON_CODE_IS_NOT_APPROVED];
            }

            $onlyThisShopProductApplyCoupon = true;
            if ($couponShopId && $item) {
                $totalCartAmount = array_reduce(
                    $item,
                    fn ($sum, $product) => $sum + (isset($product['shop_id']) && $product['shop_id'] == $couponShopId ? $product['price'] * $product['quantity'] : 0),
                    0
                );

                $isLessThanSubtotal = $sub_total >= $totalCartAmount;

                switch ($coupon->type) {
                    case CouponType::FIXED_COUPON:
                        $onlyThisShopProductApplyCoupon = $isLessThanSubtotal && $totalCartAmount > $coupon->amount;
                        break;
                    case CouponType::PERCENTAGE_COUPON:
                        $couponPercentageAmount = ($totalCartAmount * $coupon->amount) / 100;
                        $onlyThisShopProductApplyCoupon = $isLessThanSubtotal && $totalCartAmount > $couponPercentageAmount;
                        break;
                    case CouponType::FREE_SHIPPING_COUPON:
                        $onlyThisShopProductApplyCoupon = $isLessThanSubtotal && $useFreeShipping;
                        break;
                }
            }

            if (!$onlyThisShopProductApplyCoupon) {
                return ["is_valid" => false, "message" => COUPON_CODE_IS_NOT_APPLICABLE_IN_THIS_SHOP_PRODUCT];
            }

            if (
                $coupon->is_valid &&
                $useFreeShipping &&
                $coupon->type == CouponType::FREE_SHIPPING_COUPON
            ) {
                return ["is_valid" => false, "message" => ALREADY_FREE_SHIPPING_ACTIVATED];
            } elseif ($coupon->is_valid && $is_satisfy && $onlyThisShopProductApplyCoupon) {
                return ["is_valid" => true, "coupon" => $coupon];
            } elseif ($coupon->is_valid && !$is_satisfy) {
                return ["is_valid" => false, "message" => COUPON_CODE_IS_NOT_APPLICABLE];
            } else {
                return ["is_valid" => false, "message" => INVALID_COUPON_CODE];
            }
        } catch (\Exception $th) {
            return ["is_valid" => false, "message" => INVALID_COUPON_CODE];
        }
    }
}
