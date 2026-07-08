<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Marvel\Http\Requests\ShopCreateRequest;
use Marvel\Http\Requests\ShopUpdateRequest;
use Marvel\Http\Resources\VendorResource;

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
}
