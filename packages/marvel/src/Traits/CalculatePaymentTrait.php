<?php

namespace Marvel\Traits;

use Marvel\Database\Models\Product;
use Marvel\Database\Models\Variation;
use Marvel\Enums\CouponType;
use Marvel\Exceptions\MarvelException;


trait CalculatePaymentTrait
{
    use WalletsTrait;

    public function calculateSubtotal($cartItems)
    {
        if (!is_array($cartItems)) {
            throw new MarvelException(CART_ITEMS_NOT_FOUND);
        }
        $subtotal = 0;
        try {
            foreach ($cartItems as $item) {
                $qty = $item['order_quantity'];

                // Vendor cost-sheet margin price wins (server-authoritative). Products
                // without a cost sheet fall through to the normal catalog price, so
                // nothing changes for them.
                $marginUnit = $this->vendorMarginUnitPrice($item['product_id'] ?? null, $item['variation_option_id'] ?? null);
                if ($marginUnit !== null) {
                    $subtotal += $marginUnit * $qty;
                    continue;
                }

                if (isset($item['variation_option_id'])) {
                    $variation = Variation::findOrFail($item['variation_option_id']);
                    $subtotal += $this->calculateEachItemTotal($variation, $qty);
                } else {
                    $product = Product::findOrFail($item['product_id']);
                    $subtotal += $this->calculateEachItemTotal($product, $qty);
                }
            }
            return $subtotal;
        } catch (\Throwable $th) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /** Margin-over-cost unit price for a cart line, or null when the product has no cost sheet. */
    protected function vendorMarginUnitPrice($productId, $voId): ?float
    {
        try {
            if (!$productId && $voId) {
                $productId = optional(Variation::find($voId))->product_id;
            }
            if (!$productId) {
                return null;
            }
            $product = Product::find($productId);
            if (!$product) {
                return null;
            }
            $r = (new \Marvel\Services\PricingService())->sellingPrice($product, $voId ? (int) $voId : null);
            return (!empty($r['has_vendor_cost']) && !empty($r['available'])) ? (float) $r['price'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function calculateDiscount($coupon, $subtotal)
    {
        if ($coupon->id) {
            if ($coupon->type === CouponType::PERCENTAGE_COUPON) {
                return $subtotal * ($coupon->amount / 100);
            } else if ($coupon->type === CouponType::FIXED_COUPON) {
                return $coupon->amount;
            }
        } else {
            return 0;
        }
    }


    public function calculateEachItemTotal($item, $quantity)
    {
        $total = 0;
        if ($item->sale_price) {
            $total += $item->sale_price * $quantity;
        } else {
            $total += $item->price * $quantity;
        }
        return $total;
    }

    public function getUserWalletAmount($user)
    {
        $amount = 0;
        $wallet = $user->wallet;
        if (isset($wallet->available_points)) {
            $amount =  $this->walletPointsToCurrency($wallet->available_points);
        }
        return $amount;
    }
}
