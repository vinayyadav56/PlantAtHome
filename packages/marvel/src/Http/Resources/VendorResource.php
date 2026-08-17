<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;

/**
 * Vendor-semantic view of a supplier (the underlying row lives in the `shops`
 * table). Flattens the compliance/banking/location/documents settings JSON into
 * first-class vendor fields so /api/vendors reads as a clean Vendor API. Never
 * exposed to customers — vendors are internal suppliers.
 */
class VendorResource extends Resource
{
    public function toArray($request): array
    {
        // Banking, tax ids and commission are ADMIN-eyes-only. The read gate on /vendors accepts
        // any store_owner (deliberately — vendors browse the supplier directory), and this resource
        // used to flatten account_number/ifsc/pan/gst/upi for every row, so any vendor could read
        // every other vendor's banking details. Directory fields stay; money identity does not.
        $admin = (bool) optional($request->user())->hasPermissionTo(\Marvel\Enums\Permission::SUPER_ADMIN);

        $settings   = (array) ($this->settings ?? []);
        $compliance = (array) ($settings['compliance'] ?? []);
        $banking    = (array) ($settings['banking'] ?? []);
        $documents  = (array) ($settings['documents'] ?? []);
        $location   = (array) ($settings['location'] ?? []);
        $address    = (array) ($this->address ?? []);
        $payment    = (array) (optional($this->balance)->payment_info ?? []);

        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'slug'             => $this->slug,
            'description'      => $this->description,
            'logo'             => $this->logo,
            'cover_image'      => $this->cover_image,

            // Owner login (admin-created vendor account; see ShopRepository::storeShop).
            // credentials_email_sent is a transient flag set right after create/reset so
            // the admin UI knows whether to show the credentials for manual sharing.
            'owner_id'         => $this->owner_id ?? null,
            'owner_email'      => $this->when($admin && $this->relationLoaded('owner'), fn () => optional($this->owner)->email),
            'credentials_email_sent' => $this->when(isset($this->credentials_email_sent), fn () => (bool) $this->credentials_email_sent),

            // Contact
            'contact_person'   => $this->contact_person ?? null,
            'mobile'           => $this->mobile ?? ($settings['contact'] ?? null),
            'email'            => $payment['email'] ?? ($settings['notifications']['email'] ?? null),
            'website'          => $settings['website'] ?? null,

            // Compliance
            'gst_number'       => $this->when($admin, fn () => $this->gst_number ?? ($compliance['gst'] ?? null)),
            'pan'              => $this->when($admin, fn () => $compliance['pan'] ?? null),
            'constitution'     => $compliance['constitution'] ?? null,

            // Address / location (a vendor belongs to exactly one city)
            'address'          => $address['street_address'] ?? null,
            'state'            => $address['state'] ?? null,
            'city'             => $address['city'] ?? null,
            'pincode'          => $address['zip'] ?? null,
            'country'          => $address['country'] ?? null,
            'lat'              => $this->lat ?? ($location['lat'] ?? null),
            'lng'              => $this->lng ?? ($location['lng'] ?? null),

            // Banking / payout
            'bank'             => $this->when($admin, fn () => $payment['bank'] ?? null),
            'account_holder'   => $this->when($admin, fn () => $payment['name'] ?? null),
            'account_number'   => $this->when($admin, fn () => $payment['account'] ?? null),
            'ifsc'             => $this->when($admin, fn () => $banking['ifsc'] ?? null),
            'branch'           => $this->when($admin, fn () => $banking['branch'] ?? null),
            'upi'              => $this->when($admin, fn () => $this->upi ?? ($banking['upi'] ?? null)),

            // Status
            'status'           => $this->is_active ? 'active' : 'inactive',
            'is_active'        => (bool) $this->is_active,
            'approval_status'  => $this->approval_status ?? ($this->is_active ? 'approved' : 'pending'),
            'approved_at'      => $this->approved_at ?? null,

            // Commission / performance (admin only)
            'admin_commission_rate' => $this->when($admin, fn () => optional($this->balance)->admin_commission_rate),
            'vendor_rating'    => $this->vendor_rating,
            'vendor_priority_score' => $this->vendor_priority_score,

            // Relations
            'categories'       => $this->whenLoaded('categories', fn () => $this->categories->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug,
            ])->values()),
            'service_areas'    => $this->whenLoaded('serviceAreas', fn () => $this->serviceAreas),
            'documents'        => $documents,
            'documents_status' => $settings['documents_status'] ?? null,

            // Audit
            'created_by'       => $this->created_by ?? null,
            'updated_by'       => $this->updated_by ?? null,
            'created_at'       => $this->created_at,
            'updated_at'       => $this->updated_at,
        ];
    }
}
