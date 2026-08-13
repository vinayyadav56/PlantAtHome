<?php

namespace Marvel\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\HttpResponseException;
use Marvel\Enums\Permission;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\User;
use Illuminate\Http\JsonResponse;
use Marvel\Database\Models\Balance;
use Marvel\Database\Models\Product;
use Illuminate\Support\Facades\Hash;
use Marvel\Enums\ProductStatus;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\ApproveShopRequest;
use Marvel\Http\Requests\ShopCreateRequest;
use Marvel\Http\Requests\ShopUpdateRequest;
use Marvel\Http\Requests\TransferShopOwnerShipRequest;
use Marvel\Http\Requests\UserCreateRequest;
use Marvel\Http\Resources\PublicShopResource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Settings;
use Marvel\Database\Repositories\ShopRepository;
use Marvel\Enums\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ShopController extends CoreController
{
    public $repository;

    public function __construct(ShopRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return Collection|Shop[]
     */
    public function index(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;
        return $this->fetchShops($request)->paginate($limit)->withQueryString();
    }

    public function fetchShops(Request $request)
    {
        $query = $this->repository->withCount(['orders', 'products'])->with(['owner.profile', 'ownership_history'])->where('id', '!=', null);
        // Seller anonymity: hide marketplace fulfilment vendors (they declare service
        // areas) from non-admins. Customers must never see vendor shops; admins manage
        // them through the Vendors screens (super-admin token bypasses this filter).
        if (!$this->isShopAdmin($request)) {
            $query->whereDoesntHave('serviceAreas');
        } else {
            // `balance` carries admin_commission_rate — without it every row in the admin
            // shops table renders 0% and the commission modal opens empty. Admins only:
            // this route is `auth:sanctum` (not admin-gated) and index() returns the RAW
            // model, so eager-loading it unconditionally would hand current_balance,
            // total_earnings and payment_info to any signed-in customer.
            $query->with('balance');
        }
        return $query;
    }

    /** True when the requester may see marketplace vendor shops (super admin). */
    private function isShopAdmin(Request $request): bool
    {
        return $request->user() && $request->user()->hasPermissionTo(Permission::SUPER_ADMIN);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param ShopCreateRequest $request
     * @return mixed
     */
    public function store(ShopCreateRequest $request)
    {
        try {
            if ($request->user()->hasPermissionTo(Permission::STORE_OWNER)) {
                return $this->repository->storeShop($request);
            }
            throw new AuthorizationException(NOT_AUTHORIZED);
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE);
        }
    }

    /**
     * Display the specified resource.
     *
     * @param $slug
     * @return JsonResponse
     */
    public function show($slug, Request $request)
    {
        $shop = $this->repository
            ->with(['categories', 'owner', 'ownership_history', 'serviceAreas'])
            ->withCount(['orders', 'products']);
        $privileged = $request->user() && ($request->user()->hasPermissionTo(Permission::SUPER_ADMIN) || $request->user()->shops->contains('slug', $slug));
        if ($privileged) {
            $shop = $shop->with('balance');
        } else {
            // Customers cannot open a marketplace vendor's shop page → 404 on firstOrFail.
            $shop = $shop->whereDoesntHave('serviceAreas');
        }
        try {
            $model = match (true) {
                is_numeric($slug) => $shop->where('id', $slug)->firstOrFail(),
                is_string($slug)  => $shop->where('slug', $slug)->firstOrFail(),
            };
            // An anonymous caller gets the allowlisted view. Returning the raw model here
            // shipped `settings` whole (banking/compliance/documents) plus the owner's email,
            // phone and geolocation columns to anyone who knew a slug. Admins and the shop's
            // own owner keep the full model — that is what the admin shop page reads.
            return $privileged ? $model : new PublicShopResource($model);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param ShopUpdateRequest $request
     * @param int $id
     * @return array
     */
    public function update(ShopUpdateRequest $request, $id)
    {
        try {
            $request->id = $id;
            return $this->updateShop($request);
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_UPDATE_THE_RESOURCE);
        }
    }

    public function updateShop(Request $request)
    {
        $id = $request->id;
        if ($request->user()->hasPermissionTo(Permission::SUPER_ADMIN) || ($request->user()->hasPermissionTo(Permission::STORE_OWNER) && ($request->user()->shops->contains($id)))) {
            return $this->repository->updateShop($request, $id);
        }
        throw new AuthorizationException(NOT_AUTHORIZED);
    }

    public function shopMaintenanceEvent(Request $request) {
        try {
            $id = $request->shop_id;
            // SECURITY: this route is public — without this gate an anonymous attacker could POST
            // any shop_id and force that shop's entire catalog private (denial-of-business) or
            // public (overriding a real maintenance window). Require super-admin or the owner.
            // Resolve via the sanctum guard explicitly: the route has no auth:sanctum middleware,
            // so the default (session) guard's user() is always null for Bearer-token callers.
            $user = $request->user('sanctum');
            if (!$user || !(
                $user->hasPermissionTo(Permission::SUPER_ADMIN)
                || ($user->hasPermissionTo(Permission::STORE_OWNER) && $user->shops->contains('id', $id))
            )) {
                throw new AuthorizationException(NOT_AUTHORIZED);
            }
            return $this->repository->maintenanceShopEvent($request, $id);
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_UPDATE_THE_RESOURCE);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param $id
     * @return JsonResponse
     */
    public function destroy(Request $request, $id)
    {
        try {
            $request->id = $id;
            return $this->deleteShop($request);
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_DELETE_THE_RESOURCE);
        }
    }

    public function deleteShop(Request $request)
    {
        $id = $request->id;
        if ($request->user()->hasPermissionTo(Permission::SUPER_ADMIN) || ($request->user()->hasPermissionTo(Permission::STORE_OWNER) && ($request->user()->shops->contains($id)))) {
            try {
                $shop = $this->repository->findOrFail($id);
            } catch (\Exception $e) {
                throw new ModelNotFoundException(NOT_FOUND);
            }
            $shop->delete();
            return $shop;
        }
        throw new AuthorizationException(NOT_AUTHORIZED);
    }

    /**
     * F3a — super-admin reviews a vendor compliance document. Documents live at
     * settings.documents.{key}; the review status sits alongside at
     * settings.documents_status.{key} so the existing FileInput bindings are
     * untouched. Idempotent overwrite of that one key.
     */
    public function setDocumentStatus(Request $request, $id)
    {
        if (!$request->user() || !$request->user()->hasPermissionTo(Permission::SUPER_ADMIN)) {
            throw new MarvelException(NOT_AUTHORIZED);
        }
        $request->validate([
            'key'    => 'required|string|max:64',
            'status' => 'required|in:approved,rejected,pending',
            'note'   => 'nullable|string|max:500',
        ]);
        try {
            $shop = $this->repository->findOrFail($id);
        } catch (\Exception $e) {
            throw new ModelNotFoundException(NOT_FOUND);
        }

        $settings = (array) ($shop->settings ?? []);
        $statuses = (array) ($settings['documents_status'] ?? []);
        $statuses[$request->key] = [
            'status'      => $request->status,
            'note'        => $request->note,
            'reviewed_at' => now()->toIso8601String(),
            'reviewed_by' => optional($request->user())->id,
        ];
        $settings['documents_status'] = $statuses;
        $shop->settings = $settings;
        $shop->save();

        return $shop->fresh();
    }

    /**
     * POST shops/{id}/recompute-availability — operator recovery: rebuild the
     * city-availability projection for one vendor's catalogue NOW (previously
     * only reachable via the 03:30 cron or a gh workflow). Small catalogues
     * rebuild inline for instant feedback; big ones go to the availability
     * queue so the request can't time out.
     */
    public function recomputeAvailability(Request $request, $id)
    {
        if (!$request->user()->hasPermissionTo(Permission::SUPER_ADMIN)) {
            throw new MarvelException(NOT_AUTHORIZED);
        }
        $shop = Shop::findOrFail($id);
        $productCount = \Marvel\Database\Models\VendorProductPrice::where('shop_id', $shop->id)
            ->distinct('product_id')->count('product_id');
        $areaCount = \Marvel\Database\Models\VendorServiceArea::where('shop_id', $shop->id)
            ->where('is_active', true)->count();

        $inline = $productCount <= 100;
        if ($inline) {
            (new \Marvel\Services\AvailabilityService())->recomputeForShop((int) $shop->id);
        } else {
            \Marvel\Jobs\RecomputeShopAvailabilityJob::dispatch((int) $shop->id);
        }

        return [
            'ok'            => true,
            'mode'          => $inline ? 'inline' : 'queued',
            'products'      => $productCount,
            'service_areas' => $areaCount,
            // The #1 reason a vendor's catalogue is invisible: inventory exists
            // but no active service areas ⇒ the projection has no cities to write.
            'warning'       => ($productCount > 0 && $areaCount === 0)
                ? 'This vendor has inventory but ZERO active service areas — nothing can be projected into any city. Add service areas or delivery coverage first.'
                : null,
        ];
    }

    public function approveShop(ApproveShopRequest $request)
    {
        if (!$request->user()->hasPermissionTo(Permission::SUPER_ADMIN)) {
            throw new MarvelException(NOT_AUTHORIZED);
        }
        $id = $request->id;
        $admin_commission_rate = $request->admin_commission_rate;
        try {
            $shop = $this->repository->findOrFail($id);
        } catch (\Exception $e) {
            throw new ModelNotFoundException(NOT_FOUND);
        }

        // KYC go-live gate for self-serve vendors. Shop creation is intentionally NOT blocked on
        // documents (that only stalls onboarding); instead a self-serve vendor's shop cannot be
        // activated until the core compliance documents are on file. A super-admin-owned shop
        // (e.g. a house/test shop) is exempt. This is the point where "KYC required for self-serve"
        // is actually enforced.
        $owner = $shop->owner_id ? User::find($shop->owner_id) : null;
        $ownerIsSuperAdmin = $owner && $owner->getPermissionNames()->contains(Permission::SUPER_ADMIN);
        if (!$ownerIsSuperAdmin) {
            // One source of truth for the required set — the KYC service also
            // drives the registration deadline and the nightly hold sweep, so
            // the approve gate and the sweep can never disagree about what
            // "complete paperwork" means.
            $missing = app(\Marvel\Services\VendorKycService::class)->missingDocuments($shop);
            if (!empty($missing)) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Cannot approve this vendor yet — the following KYC document(s) are still missing: ' . implode(', ', $missing) . '. Ask the vendor to upload them (or add them from the shop edit page), then approve.',
                ], 422));
            }
        }

        // Activation, product publish and the commission row must all land together — a partial
        // apply left a live shop with no commission row (corrupt earnings) or vice-versa.
        return DB::transaction(function () use ($shop, $id, $admin_commission_rate) {
            $shop->is_active = true;
            // Explicit approval lifecycle alongside the legacy is_active flag.
            if (\Illuminate\Support\Facades\Schema::hasColumn('shops', 'approval_status')) {
                $shop->approval_status = 'approved';
                $shop->approved_at = now();
            }
            // Approval implies the paperwork gate passed — make sure no stale
            // KYC clock survives (it should already be clear, but the deadline
            // and the approval must never disagree).
            app(\Marvel\Services\VendorKycService::class)->syncDeadline($shop);
            $shop->save();

            // Publish ONLY products awaiting approval — never republish items the vendor has
            // deliberately drafted/unpublished (the old code force-published everything on every
            // approve, so a re-approval resurrected hidden products).
            Product::where('shop_id', '=', $id)
                ->where('status', ProductStatus::UNDER_REVIEW)
                ->update(['status' => ProductStatus::PUBLISH]);

            $balance = Balance::firstOrNew(['shop_id' => $id]);
            $balance->admin_commission_rate = $admin_commission_rate;
            $balance->save();

            return $shop;
        });
    }

    /**
     * POST update-shop-commission { id, admin_commission_rate } — edit a vendor's
     * commission % after onboarding (approve-shop sets the initial rate). The rate
     * is deducted from the vendor's earnings on settlement — it does not change
     * the customer selling price (that's the PlantAtHome margin's job).
     */
    public function updateCommission(ApproveShopRequest $request)
    {
        if (!$request->user()->hasPermissionTo(Permission::SUPER_ADMIN)) {
            throw new MarvelException(NOT_AUTHORIZED);
        }
        try {
            $shop = $this->repository->findOrFail($request->id);
        } catch (\Exception $e) {
            throw new ModelNotFoundException(NOT_FOUND);
        }
        $balance = Balance::firstOrNew(['shop_id' => $shop->id]);
        $balance->admin_commission_rate = $request->admin_commission_rate;
        $balance->save();

        return $shop->fresh(['balance']);
    }

    public function disApproveShop(Request $request)
    {
        if (!$request->user()->hasPermissionTo(Permission::SUPER_ADMIN)) {
            throw new MarvelException(NOT_AUTHORIZED);
        }
        $id = $request->id;
        try {
            $shop = $this->repository->findOrFail($id);
        } catch (\Exception $e) {
            throw new ModelNotFoundException(NOT_FOUND);
        }

        return DB::transaction(function () use ($shop, $id) {
            $shop->is_active = false;
            if (\Illuminate\Support\Facades\Schema::hasColumn('shops', 'approval_status')) {
                $shop->approval_status = 'rejected';
            }
            $shop->save();

            // Hide only currently-live products (send them back to review); leave the vendor's
            // deliberate drafts/unpublished items exactly as they are so a later re-approval
            // restores precisely what was live before, nothing more.
            Product::where('shop_id', '=', $id)
                ->where('status', ProductStatus::PUBLISH)
                ->update(['status' => ProductStatus::UNDER_REVIEW]);

            return $shop;
        });
    }

    /**
     * POST shops/{id}/extend-kyc { days, reason } — give a vendor more time to
     * supply their KYC documents.
     *
     * Pushes the deadline out from TODAY (not from the old deadline — "give
     * them 15 more days" means 15 days from now, whatever the old date was),
     * clears the reminder stamp so the warning email fires again near the new
     * date, and lifts a deadline-caused hold. Distinct from disapprove on
     * purpose: rejected is a decision, held is just "out of time".
     */
    public function extendKycDeadline(Request $request, $id)
    {
        if (!$request->user()->hasPermissionTo(Permission::SUPER_ADMIN)) {
            throw new MarvelException(NOT_AUTHORIZED);
        }
        $request->validate([
            'days'   => ['required', 'integer', 'min:1', 'max:365'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $shop = $this->repository->findOrFail($id);
        } catch (\Exception $e) {
            throw new ModelNotFoundException(NOT_FOUND);
        }

        $kyc = app(\Marvel\Services\VendorKycService::class);

        $shop->documents_due_at = now()->addDays((int) $request->days);
        $shop->kyc_reminded_at = null;
        if ($shop->approval_status === \Marvel\Database\Models\Shop::STATUS_ON_HOLD) {
            // release() saves and refreshes availability.
            $kyc->release($shop, 'KYC deadline extended: ' . ($request->reason ?: 'grace period'));
        } else {
            $shop->save();
        }

        return $shop->refresh();
    }

    public function addStaff(UserCreateRequest $request)
    {
        try {
            if ($this->repository->hasPermission($request->user(), $request->shop_id)) {
                $permissions = [Permission::CUSTOMER, Permission::STAFF];
                $user = User::create([
                    'name' => $request->name,
                    'email' => $request->email,
                    'shop_id' => $request->shop_id,
                    'password' => Hash::make($request->password),
                ]);

                $user->givePermissionTo($permissions);
                $user->assignRole(Role::STAFF);

                return true;
            }
            throw new AuthorizationException(NOT_AUTHORIZED);
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    public function deleteStaff(Request $request, $id)
    {
        try {
            $request->id = $id;
            return $this->removeStaff($request);
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_DELETE_THE_RESOURCE);
        }
    }

    public function removeStaff(Request $request)
    {
        $id = $request->id;
        try {
            $staff = User::findOrFail($id);
        } catch (\Exception $e) {
            throw new ModelNotFoundException(NOT_FOUND);
        }
        // SECURITY: the previous guard short-circuited to true for ANY store owner (the first OR
        // operand made the ownership half dead code), letting a store owner delete ANY user by id —
        // other shops' staff, competing owners, even super-admins. Require: super-admin, OR a store
        // owner deleting an actual STAFF member of a shop they own.
        $user = $request->user();
        $authorized = $user->hasPermissionTo(Permission::SUPER_ADMIN)
            || ($user->hasPermissionTo(Permission::STORE_OWNER)
                && $staff->hasPermissionTo(Permission::STAFF)
                && $user->shops->contains('id', $staff->shop_id));
        if ($authorized) {
            $staff->delete();
            return $staff;
        }
        throw new AuthorizationException(NOT_AUTHORIZED);
    }

    public function myShops(Request $request)
    {
        $user = $request->user();
        return $this->repository->where('owner_id', '=', $user->id)->get();
    }


    /**
     * Popular products by followed shops
     *
     * @param Request $request
     * @return array
     * @throws MarvelException
     */
    public function followedShopsPopularProducts(Request $request)
    {
        $request->validate([
            'limit' => 'numeric',
        ]);

        try {
            $user = $request->user();
            $userShops = User::where('id', $user->id)->with('follow_shops')->get();
            $followedShopIds = $userShops->first()->follow_shops->pluck('id')->all();
            $limit = $request->limit ? $request->limit : 10;

            $products_query = Product::withCount('orders')->with(['shop'])->whereIn('shop_id', $followedShopIds)->orderBy('orders_count', 'desc');

            return $products_query->take($limit)->get();
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * Get all the followed shops of logged-in user
     *
     * @param Request $request
     * @return mixed
     */
    public function userFollowedShops(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;
        $user = $request->user();
        $currentUser = User::where('id', $user->id)->first();

        return $currentUser->follow_shops()->paginate($limit);
    }

    /**
     * Get boolean response of a shop follow/unfollow status
     *
     * @param Request $request
     * @return bool
     * @throws MarvelException
     */
    public function userFollowedShop(Request $request)
    {
        $request->validate([
            'shop_id' => 'required|numeric',
        ]);

        try {
            $user = $request->user();
            $userShops = User::where('id', $user->id)->with('follow_shops')->get();
            $followedShopIds = $userShops->first()->follow_shops->pluck('id')->all();

            $shop_id = (int)$request->input('shop_id');

            return in_array($shop_id, $followedShopIds);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * Follow/Unfollow shop
     *
     * @param Request $request
     * @return bool
     * @throws MarvelException
     */
    public function handleFollowShop(Request $request)
    {
        $request->validate([
            'shop_id' => 'required|numeric',
        ]);

        try {
            $user = $request->user();
            $userShops = User::where('id', $user->id)->with('follow_shops')->get();
            $followedShopIds = $userShops->first()->follow_shops->pluck('id')->all();

            $shop_id = (int)$request->input('shop_id');

            if (in_array($shop_id, $followedShopIds)) {
                $followedShopIds = array_diff($followedShopIds, [$shop_id]);
            } else {
                $followedShopIds[] = $shop_id;
            }

            $response = $user->follow_shops()->sync($followedShopIds);

            if (count($response['attached'])) {
                return true;
            }

            if (count($response['detached'])) {
                return false;
            }
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    public function nearByShop($lat, $lng, Request $request)
    {
        $request['lat'] = $lat;
        $request['lng'] = $lng;

        return $this->findShopDistance($request);
    }

    public function findShopDistance(Request $request)
    {
        try {
            $settings = Settings::getData();
            $maxShopDistance = isset($settings['options']['maxShopDistance']) ? $settings['options']['maxShopDistance'] : 1000;
            $lat = $request->lat;
            $lng = $request->lng;
            if (!is_numeric($lat) || !is_numeric($lng)) {
                throw new HttpException(400, 'invalid argument');
            }

            // This haversine runs json_extract over the full active-shop set (no index) then
            // filters distance in PHP — heavy on a public storefront lookup. Cache by a coarse
            // lat/lng grid for 120s; "nearby shops" tolerates that staleness and a newly
            // activated shop appears within the window.
            // v2 prefix: the pre-2026-08-07 key holds raw shop models complete with banking
            // settings and owner PII. Without the bump, that payload keeps being served from
            // cache for 120s after this fix deploys.
            $cacheKey = 'shops:near:v2:' . round((float) $lat, 2) . ':' . round((float) $lng, 2) . ':' . $maxShopDistance;
            return Cache::remember($cacheKey, 120, function () use ($lat, $lng, $maxShopDistance) {
                $shops = Shop::where('settings->location->lat', '!=', null)
                ->where('settings->location->lng', '!=', null)
                ->select(
                    "shops.*",
                    DB::raw("6371 * acos(cos(radians(" . $lat . "))
        * cos(radians(json_unquote(json_extract(`shops`.`settings`, '$.\"location\".\"lat\"'))))
        * cos(radians(json_unquote(json_extract(`shops`.`settings`, '$.\"location\".\"lng\"'))) - radians(" . $lng . "))
        + sin(radians(" . $lat . "))
        * sin(radians(json_unquote(json_extract(`shops`.`settings`, '$.\"location\".\"lat\"'))))) AS distance")
                )
                ->orderBy('distance', 'ASC')
                ->where('is_active', 1)
                ->whereDoesntHave('serviceAreas')   // never surface marketplace vendors to customers
                    ->get()
                    ->where('distance', '<', $maxShopDistance)
                    ->values();

                // `select("shops.*")` above pulls the settings JSON — banking, compliance,
                // documents — so this public, cached lookup must not return raw models.
                // Resolved to an array inside the closure so what gets CACHED is the
                // allowlisted payload, not the models it was built from.
                return PublicShopResource::collection($shops)->resolve();
            });
        } catch (MarvelException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    /**
     * newOrInActiveShops
     *
     * @param  Request $request
     * @return Collection|Shop[]
     */
    public function newOrInActiveShops(Request $request)
    {
        try {
            $limit = $request->limit ? $request->limit : 15;
            return $this->repository->withCount(['orders', 'products'])->with(['owner.profile'])->where('is_active', '=', $request->is_active)->paginate($limit)->withQueryString();
        } catch (MarvelException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG, $e->getMessage());
        }
    }

    /**
     * transferShopOwnership
     *
     */
    public function transferShopOwnership(TransferShopOwnerShipRequest $request)
    {
        try {
            return DB::transaction(fn () => $this->repository->transferShopOwnership($request));
        } catch (MarvelException $th) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }
}
