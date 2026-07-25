<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\LocationCaptureRequest;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\User;
use Marvel\Services\LocationCaptureService;

/**
 * Admin (SUPER_ADMIN) surface of the Location Capture Email System: send /
 * regenerate capture links, per-target status summaries for the vendor +
 * customer + order screens, and the filterable audit log.
 */
class LocationCaptureController extends CoreController
{
    /** GET location-capture/summary?user_id=|vendor_id= — status card payload. */
    public function summary(Request $request, LocationCaptureService $service)
    {
        [$user, $shop] = $this->resolveTarget($request);
        $target        = $user ?? $shop;

        $latest = LocationCaptureRequest::query()
            ->when($user, fn ($q) => $q->where('user_id', $user->id))
            ->when($shop, fn ($q) => $q->where('vendor_id', $shop->id))
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'location' => [
                'verified'    => (bool) ($target->location_verified ?? false),
                'latitude'    => $target->verified_latitude,
                'longitude'   => $target->verified_longitude,
                'address'     => $target->verified_address,
                'city'        => $target->verified_city,
                'state'       => $target->verified_state,
                'country'     => $target->verified_country,
                'postal_code' => $target->verified_postal_code,
                'verified_at' => $target->location_verified_at,
            ],
            'status'         => $this->statusFor($target, $latest),
            'latest_request' => $latest ? $this->present($latest) : null,
        ]);
    }

    /** POST location-capture/requests {user_id|vendor_id} — send a capture email. */
    public function store(Request $request, LocationCaptureService $service)
    {
        [$user, $shop] = $this->resolveTarget($request);

        $result = $user
            ? $service->createForUser($user, optional($request->user())->id)
            : $service->createForVendor($shop, optional($request->user())->id);

        return response()->json([
            'message'     => 'Location capture email queued to ' . $result['request']->email . '.',
            'request'     => $this->present($result['request']),
            // Plaintext link exists ONLY in this response + the email.
            'capture_url' => $result['capture_url'],
        ], 201);
    }

    /** POST location-capture/requests/{uuid}/regenerate — supersede + fresh link. */
    public function regenerate(string $uuid, Request $request, LocationCaptureService $service)
    {
        $existing = LocationCaptureRequest::where('uuid', $uuid)->firstOrFail();

        $result = $existing->type === LocationCaptureRequest::TYPE_CUSTOMER
            ? $service->createForUser(User::findOrFail($existing->user_id), optional($request->user())->id)
            : $service->createForVendor(Shop::findOrFail($existing->vendor_id), optional($request->user())->id);

        return response()->json([
            'message'     => 'New capture link generated and emailed to ' . $result['request']->email . '.',
            'request'     => $this->present($result['request']),
            'capture_url' => $result['capture_url'],
        ], 201);
    }

    /** GET location-capture/requests — the audit log (filters: status/type/search/date). */
    public function index(Request $request)
    {
        $limit = (int) ($request->limit ?? 15);

        $query = LocationCaptureRequest::query()
            ->with(['user:id,name,email', 'vendor:id,name,slug', 'creator:id,name'])
            ->orderByDesc('id');

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('status')) {
            $status = $request->input('status');
            if ($status === LocationCaptureRequest::STATUS_EXPIRED) {
                // Expiry is derived — pending rows past their window count too.
                $query->where(function ($q) {
                    $q->where('status', LocationCaptureRequest::STATUS_EXPIRED)
                        ->orWhere(function ($q2) {
                            $q2->where('status', LocationCaptureRequest::STATUS_PENDING)
                                ->where('expires_at', '<', now());
                        });
                });
            } elseif ($status === LocationCaptureRequest::STATUS_PENDING) {
                $query->where('status', LocationCaptureRequest::STATUS_PENDING)
                    ->where('expires_at', '>=', now());
            } else {
                $query->where('status', $status);
            }
        }
        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($term) {
                $q->where('email', 'like', $term)
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $term))
                    ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', $term));
            });
        }
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->input('to') . ' 23:59:59');
        }

        $page = $query->paginate($limit);
        $page->getCollection()->transform(fn ($row) => $this->present($row, withRelations: true));

        return $page;
    }

    /* ── helpers ──────────────────────────────────────────────────────────── */

    /** @return array{0: ?User, 1: ?Shop} exactly one target resolved. */
    private function resolveTarget(Request $request): array
    {
        $userId   = $request->input('user_id');
        $vendorId = $request->input('vendor_id');
        if ((bool) $userId === (bool) $vendorId) {
            abort(422, 'Provide exactly one of user_id or vendor_id.');
        }

        if ($userId) {
            return [User::findOrFail((int) $userId), null];
        }

        return [null, $this->resolveShop((string) $vendorId)];
    }

    /**
     * Resolve a vendor to its legacy Shop from EITHER a legacy integer shop id
     * (the vendor list sends this) OR a v2 nursery UUID (the vendor edit form's
     * initialValues.id is the nursery UUID, not the legacy int). Without this,
     * a UUID silently truncated to a bogus integer → 404, and the vendor card
     * hung on "Loading location status…" with no Send button.
     */
    private function resolveShop(string $vendorId): Shop
    {
        if (ctype_digit($vendorId)) {
            return Shop::findOrFail((int) $vendorId);
        }

        // v2 nursery uuid → its mirrored legacy shop (nurseries.legacy_id → shops.id).
        if (Schema::hasTable('nurseries')) {
            $legacyId = DB::table('nurseries')->where('uuid', $vendorId)->value('legacy_id');
            if ($legacyId) {
                return Shop::findOrFail((int) $legacyId);
            }
        }

        abort(404, 'Vendor not found.');
    }

    private function statusFor($target, ?LocationCaptureRequest $latest): string
    {
        if ($target->location_verified ?? false) {
            return 'verified';
        }
        if ($latest && $latest->isPending()) {
            return 'pending';
        }

        return 'missing';
    }

    private function present(LocationCaptureRequest $row, bool $withRelations = false): array
    {
        $status = $row->status === LocationCaptureRequest::STATUS_PENDING && $row->isExpired()
            ? LocationCaptureRequest::STATUS_EXPIRED
            : $row->status;

        $out = [
            'uuid'         => $row->uuid,
            'type'         => $row->type,
            'email'        => $row->email,
            'status'       => $status,
            'latitude'     => $row->latitude,
            'longitude'    => $row->longitude,
            'accuracy'     => $row->accuracy,
            'address'      => $row->formatted_address,
            'city'         => $row->city,
            'opened_at'    => $row->opened_at,
            'expires_at'   => $row->expires_at,
            'completed_at' => $row->completed_at,
            'created_at'   => $row->created_at,
        ];

        if ($withRelations) {
            $out['user']    = $row->user ? ['id' => $row->user->id, 'name' => $row->user->name] : null;
            $out['vendor']  = $row->vendor ? ['id' => $row->vendor->id, 'name' => $row->vendor->name, 'slug' => $row->vendor->slug] : null;
            $out['sent_by'] = $row->creator?->name;
        }

        return $out;
    }
}
