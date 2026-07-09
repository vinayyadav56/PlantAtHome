<?php

namespace Marvel\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Marvel\Database\Models\Shop;
use Marvel\Enums\Permission;
use Marvel\Http\Requests\ShopCreateRequest;
use Marvel\Http\Requests\ShopUpdateRequest;
use Marvel\Http\Resources\VendorResource;
use Marvel\Mail\VendorCredentials;

/**
 * Canonical Vendor API (/api/vendors). PlantAtHome is a single storefront; a
 * "Vendor" is an internal supplier. This is a thin presentation layer over the
 * existing shop domain — it reuses ShopController's logic + ShopRepository verbatim
 * (no duplication) and just returns the vendor-semantic VendorResource. The legacy
 * /api/shops endpoints remain as deprecated back-compat aliases.
 */
class VendorController extends ShopController
{
    /** GET /vendors — paginated vendor list (admin sees all suppliers). */
    public function index(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;
        $paginator = $this->fetchShops($request)
            ->with(['categories'])
            ->paginate($limit)
            ->withQueryString();
        return VendorResource::collection($paginator);
    }

    /** GET /vendors/{idOrSlug}. */
    public function show($slug, Request $request)
    {
        $vendor = parent::show($slug, $request);
        return new VendorResource($vendor);
    }

    /** POST /vendors. */
    public function store(ShopCreateRequest $request)
    {
        $vendor = parent::store($request);
        return new VendorResource($vendor);
    }

    /** PUT/PATCH /vendors/{id}. */
    public function update(ShopUpdateRequest $request, $id)
    {
        $vendor = parent::update($request, $id);
        return new VendorResource($vendor);
    }

    /**
     * POST /vendors/{id}/reset-owner-password (super-admin only).
     * Sets a new admin-typed password on the vendor's owner account, revokes all
     * existing tokens, and emails the new credentials (failure is non-fatal — the
     * admin UI can share the credentials manually via credentials_email_sent).
     */
    public function resetOwnerPassword($id, Request $request)
    {
        if (!$request->user()->hasPermissionTo(Permission::SUPER_ADMIN)) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
        $request->validate(['new_password' => 'required|string|min:8']);
        $shop = Shop::with('owner')->find($id);
        if (!$shop || !$shop->owner) {
            throw new ModelNotFoundException(NOT_FOUND);
        }
        $owner = $shop->owner;
        // Legacy admin-owned shop: no dedicated vendor account — resetting here would
        // change the admin's own password. Point at the transfer-ownership flow instead.
        if ($owner->hasPermissionTo(Permission::SUPER_ADMIN)) {
            throw new HttpResponseException(response()->json([
                'message' => 'This vendor is owned by an admin account and has no dedicated vendor login. Use the transfer-ownership flow to assign a vendor owner first.',
            ], 422));
        }
        $owner->password = Hash::make($request->new_password);
        $owner->save();
        // Kill existing sessions so only the new credentials work from now on.
        $owner->tokens()->delete();
        try {
            Mail::to($owner->email)->send(new VendorCredentials(
                $owner->email,
                $request->new_password,
                $shop->name,
                $owner->name,
                rtrim(config('shop.dashboard_url') ?: '', '/')
            ));
            $sent = true;
        } catch (\Exception $e) {
            $sent = false;
        }
        return ['success' => true, 'credentials_email_sent' => $sent];
    }
}
