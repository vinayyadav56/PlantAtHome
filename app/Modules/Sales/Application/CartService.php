<?php

namespace App\Modules\Sales\Application;

use App\Modules\Catalog\Infrastructure\Models\Product as CatalogProduct;
use App\Modules\Catalog\Infrastructure\Models\ProductVariant as CatalogVariant;
use App\Modules\Configuration\Application\ConfigurationService;
use App\Modules\Pricing\Application\PricingService;
use App\Modules\Sales\Infrastructure\Models\Cart;
use App\Modules\Sales\Infrastructure\Models\CartItem;
use App\Shared\Application\DomainActionException;

/**
 * Cart use-cases (Section 8). Adding a line VALIDATES the configuration (Config)
 * and PRICES it (Pricing), then freezes both as snapshots on the cart item —
 * the cart never recomputes live. Does not reserve stock.
 */
class CartService
{
    public function __construct(
        private readonly ConfigurationService $config,
        private readonly PricingService $pricing,
    ) {
    }

    public function forCustomer(?string $customerUuid, ?string $city = null): Cart
    {
        if ($customerUuid) {
            $cart = Cart::where('customer_uuid', $customerUuid)->where('status', 'open')->latest('id')->first();
            if ($cart) {
                return $cart;
            }
        }

        return Cart::create(['customer_uuid' => $customerUuid, 'city' => $city, 'status' => 'open']);
    }

    /**
     * @param array<string, array<int,string>> $selection group_code => [option_uuid,…]
     */
    public function addItem(Cart $cart, string $variantUuid, string $nurseryId, array $selection, int $qty, ?string $city): CartItem
    {
        // Validate the selection server-side (Config + Rules). Reject all-at-once.
        $validation = $this->config->validateSelection($variantUuid, $selection, $city, $nurseryId);
        if ($validation->fails()) {
            throw new InvalidSelectionException($validation->violations());
        }

        $options = collect($selection)->flatten()->unique()->values()->all();
        $categoryUuid = $this->categoryOf($variantUuid);

        $priceLine = $this->pricing->priceLine([
            'variant_uuid'  => $variantUuid,
            'nursery_id'    => $nurseryId,
            'options'       => $options,
            'qty'           => $qty,
            'city'          => $city,
            'category_uuid' => $categoryUuid,
        ]);

        return $cart->items()->create([
            'variant_uuid'    => $variantUuid,
            'nursery_id'      => $nurseryId,
            'config_snapshot' => ['selection' => $selection, 'options' => $options],
            'price_snapshot'  => $priceLine,
            'qty'             => $qty,
        ]);
    }

    public function removeItem(Cart $cart, string $itemUuid): void
    {
        $cart->items()->where('uuid', $itemUuid)->delete();
    }

    /** Grand total (minor units) across all cart lines. */
    public function grandTotalMinor(Cart $cart): int
    {
        return (int) $cart->items->sum(fn (CartItem $i) => (int) ($i->price_snapshot['total']['amount_minor'] ?? 0));
    }

    private function categoryOf(string $variantUuid): ?string
    {
        $variant = CatalogVariant::where('uuid', $variantUuid)->first();
        if (! $variant) {
            throw DomainActionException::unprocessable('Variant not found.', 'VARIANT_NOT_FOUND', 'variant_uuid');
        }
        $product = CatalogProduct::find($variant->product_id);

        return $product?->category?->uuid;
    }
}
