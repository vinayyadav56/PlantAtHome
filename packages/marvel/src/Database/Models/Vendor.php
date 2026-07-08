<?php

namespace Marvel\Database\Models;

/**
 * Vendor is the canonical domain concept for a nursery/supplier. PlantAtHome is a
 * single-storefront (not a marketplace): customers only ever see PlantAtHome, and a
 * "Vendor" is an internal supplier.
 *
 * It maps onto the existing `shops` table (physical name retained for backward
 * compatibility — every FK across the commerce/money core is `shop_id`, and the
 * Spatie `store_owner` role is baked into live user assignments + tokens). New code,
 * APIs and resources should reference Vendor; the legacy Shop model is kept only for
 * the untouched Pickbazar core paths. Both point at the same row, so relationships,
 * scopes and repositories are shared with zero duplication.
 */
class Vendor extends Shop
{
    // Inherits table `shops`, $guarded=[], casts, sluggable + all 19 relations
    // (owner, orders, products, balance, withdraws, staffs, categories, serviceAreas,
    //  vendorProductPrices, shippingRates, …). Add vendor-only helpers here as needed.
}
