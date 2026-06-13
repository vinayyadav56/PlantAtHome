<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Marvel\Database\Models\DeliveryPartner;
use Marvel\Database\Models\DeliveryPartnerBalance;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\User;

/**
 * Delivery-partner onboarding + management (SUPER_ADMIN). A partner is a KYC
 * profile plus a `user` login carrying the `delivery_partner` permission. A
 * vendor can also be a DP (is_vendor_cum_dp + shop_id → shares the shop owner's
 * login and base location). `me` is the partner's own record for their dashboard.
 */
class DeliveryPartnerController extends CoreController
{
    private const PERMISSION = 'delivery_partner';

    /** Admin: paginated list (search by name / mobile / status). */
    public function index(Request $request)
    {
        $limit  = (int) ($request->limit ?? 20);
        $search = $request->search ?? $request->name;

        $query = DeliveryPartner::with(['balance', 'shop:id,name,slug'])->withCount([]);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%");
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('is_vendor_cum_dp')) {
            $query->where('is_vendor_cum_dp', (bool) $request->boolean('is_vendor_cum_dp'));
        }

        return $query->orderByDesc('id')->paginate($limit);
    }

    /** Admin: single partner with relations. */
    public function show($id)
    {
        return DeliveryPartner::with(['balance', 'shop:id,name,slug', 'user:id,name,email'])
            ->findOrFail($id);
    }

    /** Admin: onboard a delivery partner (creates login + role + zeroed balance). */
    public function store(Request $request)
    {
        $data = $this->payload($request);

        if (!$data['full_name']) {
            return response()->json(['message' => 'Full name is required.'], 422);
        }

        // Resolve / create the login user.
        $userId = null;

        if (!empty($data['is_vendor_cum_dp']) && $request->filled('shop_id')) {
            // Vendor-cum-DP: reuse the shop owner's login; grant it DP permission.
            $shop = Shop::find($request->input('shop_id'));
            if ($shop && $shop->owner_id) {
                $userId = $shop->owner_id;
                $owner = User::find($shop->owner_id);
                if ($owner && !$owner->hasPermissionTo(self::PERMISSION)) {
                    $owner->givePermissionTo(self::PERMISSION);
                    $owner->assignRole(self::PERMISSION);
                }
                // Inherit the shop's geolocation if none supplied.
                $data['lat'] = $data['lat'] ?? $shop->lat;
                $data['lng'] = $data['lng'] ?? $shop->lng;
            }
            $data['shop_id'] = $request->input('shop_id');
        } elseif ($request->filled('email') && $request->filled('password')) {
            // Standalone DP: create a fresh login.
            $user = User::create([
                'name'     => $data['full_name'],
                'email'    => $request->input('email'),
                'password' => Hash::make($request->input('password')),
            ]);
            $user->givePermissionTo(self::PERMISSION);
            $user->assignRole(self::PERMISSION);
            $userId = $user->id;
        }

        $data['user_id'] = $userId;
        $data['status']  = $data['status'] ?? 'pending';

        $partner = DeliveryPartner::create($data);
        DeliveryPartnerBalance::firstOrCreate(['delivery_partner_id' => $partner->id]);

        return DeliveryPartner::with(['balance', 'shop:id,name,slug'])->find($partner->id);
    }

    /** Admin: update partner details / commission / KYC. */
    public function update(Request $request, $id)
    {
        $partner = DeliveryPartner::findOrFail($id);
        $partner->update($this->payload($request, false));
        return DeliveryPartner::with(['balance', 'shop:id,name,slug'])->find($partner->id);
    }

    /** Admin: approve / suspend a partner. */
    public function approve(Request $request)
    {
        $partner = DeliveryPartner::findOrFail($request->input('id'));
        $partner->status    = $request->input('status', 'approved');
        $partner->is_active = $partner->status !== 'suspended';
        $partner->save();
        return $partner;
    }

    /** Admin: soft delete. */
    public function destroy($id)
    {
        $partner = DeliveryPartner::findOrFail($id);
        $partner->delete();
        return $partner;
    }

    /** DP self: their own profile + balance for the dashboard. */
    public function me(Request $request)
    {
        $partner = DeliveryPartner::with(['balance', 'shop:id,name,slug'])
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$partner) {
            return response()->json(['message' => 'No delivery-partner profile linked to this account.'], 404);
        }
        // The partner sees masked KYC only.
        $partner->makeHidden(['aadhaar_number', 'pan_number']);
        return $partner;
    }

    /** Whitelist the writable fields (KYC numbers are encrypted by the cast). */
    private function payload(Request $request, bool $withDefaults = true): array
    {
        $fields = [
            'full_name', 'mobile', 'email', 'aadhaar_number', 'pan_number',
            'aadhaar_doc', 'pan_doc', 'live_photo', 'vehicle_type',
            'address', 'location', 'lat', 'lng', 'status', 'is_active',
            'is_vendor_cum_dp', 'shop_id',
            'commission_basis', 'commission_type', 'commission_value',
            'courier_commission_basis', 'courier_commission_type', 'courier_commission_value',
            'payment_info', 'notes',
        ];
        $data = $request->only($fields);

        // Pull lat/lng out of the geocoded location object if present.
        $loc = $request->input('location');
        if (is_array($loc)) {
            $data['lat'] = $data['lat'] ?? ($loc['lat'] ?? null);
            $data['lng'] = $data['lng'] ?? ($loc['lng'] ?? null);
        }

        if ($withDefaults) {
            $data['full_name'] = $data['full_name'] ?? null;
        }
        return array_filter($data, fn ($v) => $v !== null);
    }
}
