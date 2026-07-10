<?php

namespace App\Modules\Promotions\Application;

use App\Modules\Promotions\Infrastructure\Models\Coupon;
use App\Modules\Promotions\Infrastructure\Models\Redemption;
use App\Shared\Application\DomainActionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Coupon evaluation + redemption (Section 4.10). evaluate() is the read the
 * Pricing engine calls (Section 7 step 5); it validates the coupon and returns
 * the discount in minor units WITHOUT mutating anything. redeem() records usage.
 */
class PromotionService
{
    public function createCoupon(array $data): Coupon
    {
        return Coupon::create([
            'code'               => strtoupper($data['code']),
            'type'               => $data['type'],
            'value'              => $data['value'],
            'min_subtotal'       => $data['min_subtotal'] ?? null,
            'max_discount'       => $data['max_discount'] ?? null,
            'usage_limit'        => $data['usage_limit'] ?? null,
            'per_customer_limit' => $data['per_customer_limit'] ?? null,
            'valid_from'         => $data['valid_from'] ?? null,
            'valid_to'           => $data['valid_to'] ?? null,
            'is_active'          => $data['is_active'] ?? true,
        ]);
    }

    /**
     * @return array{valid:bool, discount_minor:int, reason:?string, code:?string}
     */
    public function evaluate(string $code, int $subtotalMinor, ?string $customerUuid = null): array
    {
        $coupon = Coupon::where('code', strtoupper($code))->first();
        if (! $coupon || ! $coupon->is_active) {
            return $this->reject('UNKNOWN_COUPON');
        }

        $now = Carbon::now();
        if (($coupon->valid_from && $now->lt($coupon->valid_from)) || ($coupon->valid_to && $now->gt($coupon->valid_to))) {
            return $this->reject('COUPON_EXPIRED');
        }
        if ($coupon->min_subtotal !== null && $subtotalMinor < (int) round(((float) $coupon->min_subtotal) * 100)) {
            return $this->reject('MIN_SUBTOTAL_NOT_MET');
        }
        if ($coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit) {
            return $this->reject('USAGE_LIMIT_REACHED');
        }
        if ($coupon->per_customer_limit !== null && $customerUuid !== null) {
            $used = Redemption::where('coupon_id', $coupon->id)->where('customer_uuid', $customerUuid)->count();
            if ($used >= $coupon->per_customer_limit) {
                return $this->reject('PER_CUSTOMER_LIMIT_REACHED');
            }
        }

        $discount = $coupon->type === 'percentage'
            ? (int) round($subtotalMinor * ((float) $coupon->value) / 100)
            : (int) round(((float) $coupon->value) * 100);

        if ($coupon->max_discount !== null) {
            $discount = min($discount, (int) round(((float) $coupon->max_discount) * 100));
        }
        $discount = max(0, min($discount, $subtotalMinor)); // never exceed subtotal

        return ['valid' => true, 'discount_minor' => $discount, 'reason' => null, 'code' => $coupon->code];
    }

    /** Record a redemption + increment usage (called on order placement). */
    public function redeem(string $code, int $amountMinor, ?string $customerUuid, ?string $orderUuid): void
    {
        DB::transaction(function () use ($code, $amountMinor, $customerUuid, $orderUuid) {
            $coupon = Coupon::where('code', strtoupper($code))->lockForUpdate()->first();
            if (! $coupon) {
                throw DomainActionException::unprocessable('Unknown coupon.', 'UNKNOWN_COUPON', 'coupon');
            }
            Redemption::create([
                'coupon_id' => $coupon->id, 'customer_uuid' => $customerUuid,
                'order_uuid' => $orderUuid, 'amount' => $amountMinor / 100, 'at' => Carbon::now(),
            ]);
            $coupon->increment('used_count');
        });
    }

    private function reject(string $reason): array
    {
        return ['valid' => false, 'discount_minor' => 0, 'reason' => $reason, 'code' => null];
    }
}
