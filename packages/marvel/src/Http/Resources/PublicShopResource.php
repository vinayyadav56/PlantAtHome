<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;

/**
 * What an ANONYMOUS caller may see of a shop.
 *
 * ShopController::show and findShopDistance used to return the bare Eloquent model, which
 * carried `settings` whole — banking, compliance, documents — plus the eager-loaded `owner`
 * (email, phone, profile, and the staff geolocation columns last_lat/verified_latitude).
 * Verified live on 2026-08-07: an anonymous GET returned account_number, ifsc, pan,
 * gst_number, upi, account_holder, owner_email and admin_commission_rate.
 *
 * This is an explicit allowlist for the same reason ProductResource is one: a Resource that
 * subtracts fields leaks every field someone adds later, while a Resource that names them
 * fails safe. The storefront reads exactly two things off this endpoint — the shop name and
 * the maintenance window (see header-minimal.tsx) — so that is what it returns.
 *
 * The privileged branch in ShopController::show is untouched; admins still get the full model.
 */
class PublicShopResource extends Resource
{
    public function toArray($request): array
    {
        $settings = (array) ($this->settings ?? []);

        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'slug'        => $this->slug,
            'description' => $this->description,
            'logo'        => $this->logo,
            'cover_image' => $this->cover_image,
            'is_active'   => (bool) $this->is_active,

            // The storefront's maintenance banner. Deliberately reconstructed key by key
            // rather than passing `settings` through — that blob is where the banking and
            // compliance data lives.
            'settings'    => [
                'shopMaintenance' => $settings['shopMaintenance'] ?? null,
                'website'         => $settings['website'] ?? null,
                'socials'         => $settings['socials'] ?? null,
            ],

            // Distance is computed by findShopDistance's haversine select; absent elsewhere.
            'distance'    => $this->when(isset($this->distance), fn () => $this->distance),
        ];
    }
}
