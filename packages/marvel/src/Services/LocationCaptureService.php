<?php

namespace Marvel\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Marvel\Database\Models\LocationCaptureRequest;
use Marvel\Database\Models\Shop;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Marvel\Mail\LocationCaptured;
use Marvel\Mail\LocationCaptureMail;

/**
 * Location Capture Email System — admin-triggered GPS capture for customers
 * (users) and vendors (shops).
 *
 * Token model mirrors the house tracking_token pattern (Str::random +
 * constant-time compare) hardened one step: only the sha256 of the token is
 * stored, so a DB read can never mint a live link. The plaintext token exists
 * exactly twice — in the email and in the create/regenerate API response (so
 * the admin can copy the link).
 */
class LocationCaptureService
{
    /** @return array{request: LocationCaptureRequest, capture_url: string} */
    public function createForUser(User $user, ?int $createdBy): array
    {
        if (!$user->email) {
            abort(422, 'This customer has no email address on file.');
        }

        return $this->create([
            'user_id' => $user->id,
            'email'   => $user->email,
            'type'    => LocationCaptureRequest::TYPE_CUSTOMER,
        ], $createdBy, $user->name ?? 'there');
    }

    /** @return array{request: LocationCaptureRequest, capture_url: string} */
    public function createForVendor(Shop $shop, ?int $createdBy): array
    {
        $email = $shop->owner?->email ?: data_get($shop->settings, 'contact');
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            abort(422, 'This vendor has no usable email address (owner email missing).');
        }

        return $this->create([
            'vendor_id' => $shop->id,
            'email'     => $email,
            'type'      => LocationCaptureRequest::TYPE_VENDOR,
        ], $createdBy, $shop->name ?? 'there');
    }

    /** @return array{request: LocationCaptureRequest, capture_url: string} */
    private function create(array $target, ?int $createdBy, string $recipientName): array
    {
        // Spam guard: cap capture emails per target per day.
        $cap   = (int) config('location.max_daily_requests', 5);
        $today = LocationCaptureRequest::query()
            ->where('created_at', '>=', now()->startOfDay())
            ->where(function ($q) use ($target) {
                if (!empty($target['user_id'])) {
                    $q->where('user_id', $target['user_id']);
                } else {
                    $q->where('vendor_id', $target['vendor_id']);
                }
            })
            ->count();
        if ($cap > 0 && $today >= $cap) {
            abort(429, "Daily limit reached ({$cap} location emails per recipient per day).");
        }

        // A new link supersedes any live pending one — exactly one valid link at a time.
        LocationCaptureRequest::query()
            ->where('status', LocationCaptureRequest::STATUS_PENDING)
            ->when(!empty($target['user_id']), fn ($q) => $q->where('user_id', $target['user_id']))
            ->when(!empty($target['vendor_id']), fn ($q) => $q->where('vendor_id', $target['vendor_id']))
            ->update(['status' => LocationCaptureRequest::STATUS_SUPERSEDED]);

        $token = Str::random(48);

        $request = LocationCaptureRequest::create($target + [
            'uuid'       => (string) Str::uuid(),
            'token_hash' => hash('sha256', $token),
            'status'     => LocationCaptureRequest::STATUS_PENDING,
            'expires_at' => now()->addHours((int) config('location.capture_link_expiry_hours', 72)),
            'created_by' => $createdBy,
        ]);

        $captureUrl = $this->captureUrl($token);

        // Queued (never blocks the admin request); SendGrid HTTPS when configured.
        try {
            $mailer = config('mail.mailers.sendgrid.key') ? 'sendgrid' : config('mail.default');
            Mail::mailer($mailer)
                ->to($request->email)
                ->queue(new LocationCaptureMail($recipientName, $captureUrl, $request->uuid, (int) config('location.capture_link_expiry_hours', 72)));
        } catch (\Throwable $e) {
            Log::warning('Location capture email failed to queue', ['uuid' => $request->uuid, 'error' => $e->getMessage()]);
        }

        return ['request' => $request, 'capture_url' => $captureUrl];
    }

    public function captureUrl(string $token): string
    {
        $base = rtrim((string) (config('location.capture_base_url') ?: url('/location')), '/');

        return $base . '/' . $token;
    }

    public function findByToken(?string $token): ?LocationCaptureRequest
    {
        if (!$token || strlen($token) < 24) {
            return null;
        }

        return LocationCaptureRequest::where('token_hash', hash('sha256', $token))->first();
    }

    /**
     * One-time completion: validates, reverse-geocodes, stores on the request
     * AND the target user/shop. The pending→completed flip is atomic, so a
     * double submit (or replay) can never overwrite a captured location.
     *
     * @return array{ok: bool, error?: string, request?: LocationCaptureRequest}
     */
    public function complete(
        string $token,
        float $latitude,
        float $longitude,
        ?float $accuracy,
        ?string $ip,
        ?string $userAgent,
    ): array {
        $request = $this->findByToken($token);
        if (!$request) {
            return ['ok' => false, 'error' => 'invalid'];
        }
        if ($request->status === LocationCaptureRequest::STATUS_COMPLETED) {
            return ['ok' => false, 'error' => 'used'];
        }
        if (!$request->isPending()) {
            return ['ok' => false, 'error' => 'expired'];
        }

        if (abs($latitude) > 90 || abs($longitude) > 180 || ($latitude === 0.0 && $longitude === 0.0)) {
            return ['ok' => false, 'error' => 'coords'];
        }

        $minAccuracy = (int) config('location.min_accuracy_meters', 500);
        if ($minAccuracy > 0 && $accuracy !== null && $accuracy > $minAccuracy) {
            return ['ok' => false, 'error' => 'accuracy'];
        }

        $geo = $this->reverseGeocode($latitude, $longitude);

        // Atomic one-time claim — only the first submit wins.
        $claimed = LocationCaptureRequest::where('id', $request->id)
            ->where('status', LocationCaptureRequest::STATUS_PENDING)
            ->update([
                'status'            => LocationCaptureRequest::STATUS_COMPLETED,
                'completed_at'      => now(),
                'latitude'          => $latitude,
                'longitude'         => $longitude,
                'accuracy'          => $accuracy,
                'formatted_address' => $geo['formatted_address'],
                'city'              => $geo['city'],
                'state'             => $geo['state'],
                'country'           => $geo['country'],
                'postal_code'       => $geo['postal_code'],
                'google_place_id'   => $geo['place_id'],
                'ip_address'        => $ip ? mb_substr($ip, 0, 64) : null,
                'user_agent'        => $userAgent ? mb_substr($userAgent, 0, 512) : null,
            ]);
        if ($claimed === 0) {
            return ['ok' => false, 'error' => 'used'];
        }

        $request->refresh();
        $this->applyToTarget($request, $latitude, $longitude, $geo);

        return ['ok' => true, 'request' => $request];
    }

    /* ── target updates ───────────────────────────────────────────────────── */

    private function applyToTarget(LocationCaptureRequest $request, float $lat, float $lng, array $geo): void
    {
        $verified = [
            'verified_latitude'    => $lat,
            'verified_longitude'   => $lng,
            'verified_address'     => $geo['formatted_address'],
            'verified_city'        => $geo['city'],
            'verified_state'       => $geo['state'],
            'verified_country'     => $geo['country'],
            'verified_postal_code' => $geo['postal_code'],
            'verified_place_id'    => $geo['place_id'],
            'location_verified'    => true,
            'location_verified_at' => now(),
        ];

        if ($request->user_id && ($user = User::find($request->user_id))) {
            $user->forceFill($verified)->save();
        }

        if ($request->vendor_id && ($shop = Shop::find($request->vendor_id))) {
            $shop->forceFill($verified);
            // Feed the existing matching pipeline: MatchingService::shopLatLng()
            // reads settings.location{lat,lng} first, then shops.lat/lng.
            $shop->lat = $lat;
            $shop->lng = $lng;
            $settings                    = (array) ($shop->settings ?? []);
            $settings['location']        = array_merge((array) ($settings['location'] ?? []), [
                'lat'               => $lat,
                'lng'               => $lng,
                'formattedAddress'  => $geo['formatted_address'],
            ]);
            $shop->settings = $settings;
            $shop->save();

            $this->notifyAdmins($request);
        }
    }

    /** Vendor location captured → tell the super admins (server-resolved recipients). */
    private function notifyAdmins(LocationCaptureRequest $request): void
    {
        try {
            $emails = Cache::remember(
                'location_admin_emails',
                900,
                fn () => User::where('is_active', true)
                    ->permission(Permission::SUPER_ADMIN)
                    ->pluck('email')
                    ->filter()
                    ->values()
                    ->all()
            );
            if (empty($emails)) {
                return;
            }
            $mailer = config('mail.mailers.sendgrid.key') ? 'sendgrid' : config('mail.default');
            Mail::mailer($mailer)->to($emails)->queue(new LocationCaptured($request->fresh(['vendor', 'user'])));
        } catch (\Throwable $e) {
            Log::warning('Location captured admin notice failed', ['uuid' => $request->uuid, 'error' => $e->getMessage()]);
        }
    }

    /* ── geocoding ────────────────────────────────────────────────────────── */

    /**
     * Full-detail reverse geocode (formatted address / country / place_id come
     * straight from Google; canonical city/state/pincode come from the shared
     * ReverseGeocodeService ladder, which the shopping-city flow already uses).
     */
    private function reverseGeocode(float $lat, float $lng): array
    {
        $out = [
            'formatted_address' => null,
            'city'              => null,
            'state'             => null,
            'country'           => null,
            'postal_code'       => null,
            'place_id'          => null,
        ];

        $key = config('location.google_maps_key') ?: env('GOOGLE_MAP_API_KEY');
        if ($key) {
            try {
                $json = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'latlng' => $lat . ',' . $lng,
                    'key'    => $key,
                ])->json();
                $first = (array) (($json['results'] ?? [])[0] ?? []);
                $out['formatted_address'] = $first['formatted_address'] ?? null;
                $out['place_id']          = $first['place_id'] ?? null;
                foreach ((array) ($first['address_components'] ?? []) as $component) {
                    $types = (array) ($component['types'] ?? []);
                    $name  = $component['long_name'] ?? null;
                    if (in_array('country', $types, true)) {
                        $out['country'] = $name;
                    }
                    if (!$out['city'] && (in_array('locality', $types, true) || in_array('postal_town', $types, true))) {
                        $out['city'] = $name;
                    }
                    if (in_array('administrative_area_level_1', $types, true)) {
                        $out['state'] = $name;
                    }
                    if (in_array('postal_code', $types, true)) {
                        $out['postal_code'] = $name;
                    }
                }
            } catch (\Throwable $e) {
                // fail-open — canon overlay below may still fill the gaps
            }
        }

        // Overlay canonical names from the shared shopping-city ladder when it
        // succeeds (postal master beats raw Google spellings); Google values
        // above remain the fallback if the canon service can't resolve.
        try {
            $canon = app(ReverseGeocodeService::class)->resolve($lat, $lng);
            foreach (['city' => 'city', 'state' => 'state', 'postal_code' => 'pincode'] as $mine => $theirs) {
                if (!empty($canon[$theirs])) {
                    $out[$mine] = $canon[$theirs];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Location capture canon geocode failed', ['error' => $e->getMessage()]);
        }

        return $out;
    }

    /* ── dispatch gate ────────────────────────────────────────────────────── */

    /**
     * Flag-gated dispatch protection: when enabled, orders whose customer has
     * no verified location cannot be assigned/booked. OFF by default so that
     * existing customers (none verified yet) don't freeze operations.
     */
    public function assertCustomerVerifiedForDispatch($order): void
    {
        if (!config('location.require_verified_for_dispatch', false)) {
            return;
        }
        $customerId = $order->customer_id ?? null;
        if (!$customerId) {
            return; // guest orders are out of scope for the gate
        }
        $user = User::find($customerId);
        if ($user && $user->location_verified) {
            return;
        }
        abort(422, 'Customer location must be verified before dispatch. Send a location capture email from the order page.');
    }
}
