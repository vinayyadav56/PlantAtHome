<?php


namespace Marvel\Database\Repositories;

use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Balance;
use Marvel\Database\Models\City;
use Marvel\Database\Models\OwnershipTransfer;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\User;
use Marvel\Database\Models\VendorProductPrice;
use Marvel\Database\Models\VendorServiceArea;
use Marvel\Services\AvailabilityService;
use Marvel\Enums\DefaultStatusType;
use Marvel\Enums\Permission;
use Marvel\Enums\ProductVisibilityStatus;
use Marvel\Enums\Role;
use Marvel\Events\ProcessOwnershipTransition;
use Marvel\Events\ShopMaintenance;
use Marvel\Mail\VendorCredentials;
use Marvel\Http\Requests\TransferShopOwnerShipRequest;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ShopRepository extends BaseRepository
{

    /**
     * @var array
     */
    protected $fieldSearchable = [
        'name'        => 'like',
        'is_active',
        'categories.slug',
        'users.name'
    ];

    /**
     * @var array
     */
    protected $dataArray = [
        'name',
        'slug',
        'description',
        'cover_image',
        'logo',
        'is_active',
        'address',
        'settings',
        'notifications',
        'lat',
        'lng',
        // Vendor profile fields (shops = the vendors table).
        'contact_person',
        'mobile',
        'upi',
        // Delivery capability (columns, not settings keys — settings are
        // full-replaced on update and would silently wipe these).
        'delivery_mode',
        'self_delivery',
    ];

    /**
     * Replace a vendor's served cities (vendor_service_areas) from the onboarding
     * payload. Only runs when `service_areas` is present, so a partial update that
     * omits it leaves the existing areas intact.
     */
    private function syncServiceAreas(Shop $shop, $request): void
    {
        $areas = $request['service_areas'] ?? null;
        if (!is_array($areas)) {
            return;
        }
        // Replace only the vendor's MANUAL rows (source NULL). Rows written by
        // the Delivery Coverage bridge (source='coverage_sync') are derived from
        // coverage rules and must survive an onboarding-form save — the
        // projector owns their lifecycle. (Recreated rows below get source=NULL
        // implicitly, keeping them in the manual bucket.)
        VendorServiceArea::where('shop_id', $shop->id)
            ->when(
                \Illuminate\Support\Facades\Schema::hasColumn('vendor_service_areas', 'source'),
                fn ($q) => $q->whereNull('source')
            )
            ->delete();
        $seen = [];
        foreach ($areas as $area) {
            $city = trim((string) ($area['city'] ?? ''));
            if ($city === '') {
                continue;
            }
            $pincode = isset($area['pincode']) && trim((string) $area['pincode']) !== '' ? trim((string) $area['pincode']) : null;
            $key = strtolower($city) . '|' . ($pincode ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $mode = $area['fulfillment_mode'] ?? 'local';
            VendorServiceArea::create([
                'shop_id'          => $shop->id,
                'city'             => $city,
                'pincode'          => $pincode,
                'fulfillment_mode' => in_array($mode, ['local', 'courier', 'both'], true) ? $mode : 'local',
                'eta_days'         => isset($area['eta_days']) && $area['eta_days'] !== '' ? (int) $area['eta_days'] : null,
                'is_active'        => $area['is_active'] ?? true,
            ]);
        }

        // The cities a vendor serves feed the city-availability projection — refresh
        // every product this vendor supplies so the storefront reflects the change.
        if (VendorProductPrice::where('shop_id', $shop->id)->exists()) {
            // Queued + deduped: a shop save can touch hundreds of products.
            \Marvel\Jobs\RecomputeShopAvailabilityJob::dispatch((int) $shop->id);
        }

        // Onboarding a vendor in a city must surface that city in the storefront
        // picker automatically ("create a vendor in Delhi → Delhi shows up") —
        // include the vendor's own address city alongside the served areas.
        $this->activateServedCities(array_merge(
            array_map(fn ($k) => explode('|', (string) $k)[0], array_keys($seen)),
            [(string) data_get($request, 'address.city', '')]
        ));
    }

    /**
     * Flip is_serviceable on for the given city names so they appear in the
     * storefront city picker (which lists is_serviceable + active/maintenance
     * cities). Master-city rows already exist for ~1,650 Indian cities (seeded
     * serviceable=false). Deliberately DISABLED cities (ops kill switch) are
     * never overridden, and nothing is ever auto-deactivated here.
     */
    private function activateServedCities(array $cityNames): void
    {
        // Canonical keys, and then every raw spelling of each. A bare lowercase meant a vendor whose
        // service area said "South Delhi" activated the SOUTH DELHI row — a district that is not a
        // shopping city — while Delhi itself stayed switched off and the vendor's stock stayed
        // invisible in the city they had just told us they serve.
        $names = [];
        foreach ($cityNames as $n) {
            $key = \Marvel\Services\AvailabilityService::canonicalCityKey((string) $n);
            if ($key !== '') {
                $names = array_merge($names, \Marvel\Services\AvailabilityService::canonicalCityVariants($key));
            }
        }
        $names = array_values(array_unique($names));
        if (!$names) {
            return;
        }
        City::whereIn(DB::raw('LOWER(name)'), $names)
            ->when(
                Schema::hasColumn('cities', 'is_subdivision'),
                // Never flip a district on: it is not somewhere we ship to.
                fn ($q) => $q->where('is_subdivision', false),
            )
            ->where('status', '!=', City::STATUS_DISABLED)
            ->where('is_serviceable', false)
            ->update(['is_serviceable' => true]);
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
        return Shop::class;
    }

    /**
     * Normalise the GST number the onboarding form stores inside
     * settings.compliance.gst so it can be mirrored into the shops.gst_number
     * column (the source ReportController reads for GST reports/invoices).
     */
    private function gstFromSettings($request): ?string
    {
        $gst = $request['settings']['compliance']['gst'] ?? null;
        $gst = is_string($gst) ? strtoupper(trim($gst)) : null;
        return $gst !== null && $gst !== '' ? $gst : null;
    }

    public function storeShop($request)
    {
        // Vendor owner created inside the transaction (when an admin supplies owner_*
        // credentials); captured here so the credentials email goes out post-commit.
        $owner = null;
        try {
            // Shop insert + category attach + balance row + service areas must be all-or-nothing;
            // a partial failure previously left an orphan shop with no balance/areas.
            $shop = DB::transaction(function () use ($request, &$owner) {
                $data = $request->only($this->dataArray);
                $data['slug'] = $this->makeSlug($request);
                // Admin-created vendor login: a super-admin may supply owner_email/owner_password
                // so the vendor gets a dedicated account (owner_* is validated in ShopCreateRequest
                // and is never part of $dataArray, so it never touches the shops table).
                if ($request->user()->hasPermissionTo(Permission::SUPER_ADMIN) && $request->filled('owner_email')) {
                    $owner = User::create([
                        'name'     => $request->owner_name ?: $request->contact_person ?: $request->name,
                        'email'    => $request->owner_email,
                        'password' => Hash::make($request->owner_password),
                    ]);
                    $owner->givePermissionTo([Permission::CUSTOMER, Permission::STORE_OWNER]);
                    $owner->assignRole(Role::STORE_OWNER);
                    $data['owner_id'] = $owner->id;
                } else {
                    $data['owner_id'] = $request->user()->id;
                }
                // SECURITY: a newly-created shop is inactive until a super-admin approves it; a store
                // owner cannot self-activate by passing is_active=true.
                if (!$request->user()->hasPermissionTo(Permission::SUPER_ADMIN)) {
                    unset($data['is_active']);
                }
                if (array_key_exists('settings', $data)) {
                    $data['gst_number'] = $this->gstFromSettings($request);
                }
                // Audit stamp + mirror mobile into settings.contact for BC readers.
                $data['created_by'] = $request->user()->id ?? null;
                $data['updated_by'] = $request->user()->id ?? null;
                $shop = $this->create($data);
                if (isset($request['categories'])) {
                    $shop->categories()->attach($request['categories']);
                }
                // SECURITY: only the operator-editable payment_info is mass-assignable on the balance;
                // never current_balance/total_earnings/withdrawn_amount from client input.
                if (isset($request['balance']['payment_info'])) {
                    $shop->balance()->create(Arr::only($request['balance'], ['payment_info']));
                }
                $this->syncServiceAreas($shop, $request);

                // Start the KYC clock: a vendor may register without documents
                // (deliberate — paperwork must never stall onboarding), but the
                // gap gets a deadline, after which the nightly sweep holds the
                // account. Complete documents at create ⇒ no clock at all.
                app(\Marvel\Services\VendorKycService::class)->syncDeadline($shop);
                $shop->save();

                return $shop;
            });

            // Credentials email model: the admin typed the password, so we mail it to the new
            // owner AFTER commit (never inside the transaction — a mail failure must not roll
            // back the shop). Email failure is non-fatal because the admin UI shows the
            // credentials for manual sharing; credentials_email_sent tells it whether to.
            if ($owner) {
                // Queued through the engine; "sent" now means "accepted by the
                // engine" — the email log page shows the real outcome.
                $logId = app(\Marvel\Services\EmailService::class)->send('vendor.credentials', $owner->email, [
                    'vendor_name' => $owner->name,
                    'vendor_email' => $owner->email,
                    'shop_name' => $shop->name,
                    'temp_password' => $request->owner_password,
                ], [
                    'fallback' => fn () => Mail::to($owner->email)->queue(new VendorCredentials(
                        $owner->email, $request->owner_password, $shop->name, $owner->name,
                        rtrim(config('shop.dashboard_url') ?: '', '/')
                    )),
                ]);
                $shop->setAttribute('credentials_email_sent', (bool) $logId);
            }

            return $shop;
        } catch (Exception $e) {
            throw new HttpException(400, COULD_NOT_CREATE_THE_RESOURCE);
        }
    }

    public function updateShop($request, $id)
    {
        try {
            return DB::transaction(function () use ($request, $id) {
                $shop = $this->findOrFail($id);
                if (isset($request['categories'])) {
                    $shop->categories()->sync($request['categories']);
                }
                if (isset($request['balance'])) {
                    $this->updateBalance($request['balance'], $id, $request->user()->hasPermissionTo(Permission::SUPER_ADMIN));
                }
                $data = $request->only($this->dataArray);
                // SECURITY: only a super-admin (via the approve/disApprove flow) may set is_active —
                // a store owner must not self-activate their shop and bypass admin approval.
                if (!$request->user()->hasPermissionTo(Permission::SUPER_ADMIN)) {
                    unset($data['is_active']);
                }
                if (array_key_exists('settings', $data)) {
                    $data['gst_number'] = $this->gstFromSettings($request);
                }
                if (!empty($request->slug) &&  $request->slug != $shop['slug']) {
                    $data['slug'] = $this->makeSlug($request);
                }
                $data['updated_by'] = $request->user()->id ?? null;
                $shop->update($data);
                $this->syncServiceAreas($shop, $request);

                // Keep the KYC clock honest on every settings write: uploading
                // the last missing document clears the deadline (and releases a
                // KYC hold); deleting one starts the clock. No-op when nothing
                // about the document set changed.
                if (array_key_exists('settings', $data)) {
                    app(\Marvel\Services\VendorKycService::class)->syncDeadline($shop->refresh());
                    $shop->save();
                }

            // TODO : why this code is needed
            // $shop->categories = $shop->categories;
            // $shop->staffs = $shop->staffs;
            // $shop->balance = $shop->balance;


            // 1. Shop owner maintenance time set korbe.. then ekta event fire hobe jeita shop notifications (email, sms) send korbe super-admin, vendor, staff, oi specific shop er front-end a ekta notice dekhabe with countdown.
            // 2. countDown start er 1 day ago or 6 hours ago ekta final email/sms dibe vendor, staff k 
            // 3. countdown onStart a sob product private
            // 4. countdown onComplete a sob product public

                if (isset($request['settings']['isShopUnderMaintenance'])) {
                    if ($request['settings']['isShopUnderMaintenance']) {
                        event(new ShopMaintenance($shop, 'enable'));
                    } else {
                        event(new ShopMaintenance($shop, 'disable'));
                    }
                }

                return $shop;
            });
        } catch (Exception $e) {
            throw new HttpException(400, COULD_NOT_UPDATE_THE_RESOURCE);
        }
    }

    public function maintenanceShopEvent($request, $id)
    {
        $shop = $this->findOrFail($id);
        if ($request['isShopUnderMaintenance'] && $request['isMaintenance']) {
            Product::where('shop_id', '=', $id)->update(['visibility' => ProductVisibilityStatus::VISIBILITY_PRIVATE]);
            event(new ShopMaintenance($shop, 'start'));
        } else {
            Product::where('shop_id', '=', $id)->update(['visibility' => ProductVisibilityStatus::VISIBILITY_PUBLIC]);
            event(new ShopMaintenance($shop, 'disable'));
        }
    }

    /**
     * Update a shop's balance row. SECURITY: resolve the row by the AUTHORIZED $shop_id (never a
     * client-supplied balance['id'], which allowed cross-tenant writes) and only ever write the
     * operator-editable payment_info — current_balance / total_earnings / withdrawn_amount are
     * strictly server-derived from orders/settlements/withdrawals and must never be mass-assigned.
     * admin_commission_rate is writable only by a super-admin.
     */
    public function updateBalance($balance, $shop_id, $isSuperAdmin = false)
    {
        $editable = Arr::only((array) $balance, ['payment_info']);
        if ($isSuperAdmin && array_key_exists('admin_commission_rate', (array) $balance)) {
            $editable['admin_commission_rate'] = $balance['admin_commission_rate'];
        }

        $row = Balance::where('shop_id', $shop_id)->first();
        if ($row) {
            if (!empty($editable)) {
                $row->update($editable);
            }
        } else {
            $editable['shop_id'] = $shop_id;
            Balance::create($editable);
        }
    }

    public function transferShopOwnership(TransferShopOwnerShipRequest $request)
    {
        $user = $request->user();
        $shopId = $request->shop_id ?? null;

        if (!$this->hasPermission($user, $shopId)) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }

        $shop = $this->findOrFail($shopId);
        $previousOwner = $shop->owner;

        $newOwnerId = $request->vendor_id;
        $newOwner = User::findOrFail($newOwnerId);

        OwnershipTransfer::updateOrCreate(
            [
                "shop_id"    => $shopId,
            ],
            [
                "from"       => $previousOwner->id,
                "message"    => $request?->message,
                "to"         => $newOwnerId,
                "created_by" => $user->id,
                "status"     => DefaultStatusType::PENDING,
            ]
        );

        $optional = [
            'message' =>  $request?->vendorMessage,
        ];

        event(new ProcessOwnershipTransition($shop, $previousOwner, $newOwner, $optional));

        return $shop;
    }
}
