<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Marvel\Database\Models\DeliveryPartner;
use Marvel\Database\Models\DeliveryPartnerBalance;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\User;
use Marvel\Database\Repositories\OrderRepository;
use Spatie\Permission\Guard;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

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

        if (empty($data['full_name'])) {
            return response()->json(['message' => 'Full name is required.'], 422);
        }

        $isVendorCumDp = !empty($data['is_vendor_cum_dp']);

        // Validate the chosen onboarding path up front (clear 422 over a later 500).
        if ($isVendorCumDp) {
            if (!$request->filled('shop_id')) {
                return response()->json(['message' => 'Select the vendor shop for a vendor-cum-partner.'], 422);
            }
        } elseif (!($request->filled('email') && $request->filled('password'))) {
            return response()->json(['message' => 'Email and password are required to create the partner login.'], 422);
        }

        try {
            // Resolve / create the login user.
            $userId = null;

            if ($isVendorCumDp) {
                // Vendor-cum-DP: reuse the shop owner's login; grant it the DP role.
                $shop = Shop::find($request->input('shop_id'));
                if (!$shop || !$shop->owner_id) {
                    return response()->json(['message' => 'That vendor shop has no owner login to link.'], 422);
                }
                $userId = $shop->owner_id;
                $owner  = User::find($shop->owner_id);
                if ($owner) {
                    $this->ensureDeliveryRole($owner);
                }
                // Inherit the shop's geolocation if none supplied.
                $data['lat']     = $data['lat'] ?? $shop->lat;
                $data['lng']     = $data['lng'] ?? $shop->lng;
                $data['shop_id'] = $shop->id;
            } else {
                // Standalone DP: create a fresh login.
                if (User::where('email', $request->input('email'))->exists()) {
                    return response()->json(['message' => 'A user with this email already exists.'], 422);
                }
                $user = User::create([
                    'name'     => $data['full_name'],
                    'email'    => $request->input('email'),
                    'password' => Hash::make($request->input('password')),
                ]);
                $this->ensureDeliveryRole($user);
                $userId = $user->id;
            }

            $data['user_id'] = $userId;
            $data['status']  = $data['status'] ?? 'pending';

            $partner = DeliveryPartner::create($data);
            DeliveryPartnerBalance::firstOrCreate(['delivery_partner_id' => $partner->id]);

            return DeliveryPartner::with(['balance', 'shop:id,name,slug'])->find($partner->id);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Could not create the delivery partner. Please try again.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Self-healing role grant. Ensures the `delivery_partner` permission + role
     * exist for the user's own guard (the marvel User is `api`-guarded, while
     * permissions seeded guardless default to `web` — a string assign would then
     * throw PermissionDoesNotExist). findOrCreate never throws; assigning by
     * model object shares the guard, so this is safe regardless of seed state.
     */
    private function ensureDeliveryRole(User $user): void
    {
        // Resolve the user's guard exactly as Spatie does (reflection on the
        // model's declared $guard_name = 'api'). Using $user->guard_name here
        // would return null — the property is shadowed by Eloquent's __get —
        // which would create a `web` permission and then throw GuardDoesNotMatch
        // on assignment. Guard::getNames() is the source of truth.
        $guard = Guard::getNames($user)->first() ?? config('auth.defaults.guard');

        $permission = Permission::findOrCreate(self::PERMISSION, $guard);
        $role       = Role::findOrCreate(self::PERMISSION, $guard);
        if (!$role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if (!$user->hasPermissionTo($permission)) {
            $user->givePermissionTo($permission);
        }
        if (!$user->hasRole($role)) {
            $user->assignRole($role);
        }
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

    /** DP self: orders assigned to me, with pickup (vendor) + drop (customer) details. */
    public function myOrders(Request $request)
    {
        $dp = DeliveryPartner::where('user_id', $request->user()->id)->first();
        if (!$dp) {
            return response()->json(['message' => 'No delivery-partner profile linked to this account.'], 404);
        }
        $limit = (int) ($request->limit ?? 20);
        $orders = Order::where('delivery_partner_id', $dp->id)
            ->orderByDesc('id')
            ->paginate($limit, [
                'id', 'tracking_number', 'order_status', 'customer_name', 'customer_contact',
                'shipping_address', 'vendor_shop_id', 'delivery_mode', 'total',
                'dp_commission_amount', 'assignment_status', 'created_at',
            ]);

        $shopIds = $orders->getCollection()->pluck('vendor_shop_id')->filter()->unique();
        $shops = Shop::whereIn('id', $shopIds)->get()->keyBy('id');

        $orders->getCollection()->transform(function ($o) use ($shops) {
            $o->makeVisible(['shipping_address', 'vendor_shop_id', 'delivery_mode', 'dp_commission_amount', 'assignment_status']);
            $vendor = $shops[$o->vendor_shop_id] ?? null;
            $o->pickup = $vendor ? [
                'shop_id'  => $vendor->id,
                'name'     => $vendor->name,
                'location' => is_array($vendor->settings) ? ($vendor->settings['location'] ?? null) : null,
                'address'  => $vendor->address ?? null,
            ] : null;
            return $o;
        });
        return $orders;
    }

    /** DP self: advance the status of an order assigned to me (pickup → out → delivered). */
    public function updateMyOrderStatus(Request $request, $id)
    {
        $dp = DeliveryPartner::where('user_id', $request->user()->id)->first();
        if (!$dp) {
            return response()->json(['message' => 'No delivery-partner profile.'], 404);
        }
        $order = Order::find($id);
        if (!$order || (int) $order->delivery_partner_id !== (int) $dp->id) {
            return response()->json(['message' => 'This order is not assigned to you.'], 403);
        }
        $allowed = ['order-at-local-facility', 'order-out-for-delivery', 'order-completed'];
        $status = $request->input('order_status');
        if (!in_array($status, $allowed, true)) {
            return response()->json(['message' => 'Invalid status for a delivery partner.'], 422);
        }
        // changeOrderStatus credits the DP's balance when it reaches completed.
        app(OrderRepository::class)->changeOrderStatus($order, $status);
        return $order->fresh()->makeVisible(['delivery_mode', 'dp_commission_amount', 'assignment_status']);
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

        // Coerce numeric/decimal columns: blank strings must become null, not ''
        // (an empty string into a decimal column is a hard DB error → 500).
        foreach (['lat', 'lng', 'commission_value', 'courier_commission_value'] as $num) {
            if (array_key_exists($num, $data)) {
                $data[$num] = ($data[$num] === '' || $data[$num] === null) ? null : (float) $data[$num];
            }
        }

        if ($withDefaults) {
            $data['full_name'] = $data['full_name'] ?? null;
        }
        return array_filter($data, fn ($v) => $v !== null);
    }
}
