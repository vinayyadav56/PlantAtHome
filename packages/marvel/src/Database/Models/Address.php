<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;

class Address extends Model
{
    protected $table = 'address';

    public $guarded = [];

    protected $casts = [
        'address' => 'json',
        'location' => 'json'
    ];

    /**
     * Canonical address JSON shape — the ONE field-set every writer uses
     * (saved addresses, order snapshots, shop addresses). Readers stay
     * tolerant of legacy aliases; writers emit only these keys.
     */
    public const JSON_KEYS = [
        'house_no', 'street_address', 'street_address2', 'area', 'landmark',
        // `district` is the administrative slice of a city (South Delhi within Delhi). It exists
        // so `city` can stay canonical: geocoders answer "what city?" with a district, and without
        // somewhere to put it, normalising the city would simply throw the detail away.
        'city', 'district', 'state', 'zip', 'country', 'delivery_instructions',
    ];

    /** Table-level keys a client may set (rg_* are always server-derived). */
    public const TABLE_KEYS = [
        'title', 'type', 'default', 'address', 'location', 'address_type',
        'recipient_name', 'recipient_phone', 'latitude', 'longitude', 'google_place_id',
    ];

    /**
     * Whitelist + soft-normalize a client address payload. This model has
     * $guarded = [], so every writer (the /addresses routes, the legacy
     * PUT /users/{id} array path, the admin user-create path) MUST route
     * through here — otherwise arbitrary column injection.
     *
     * Soft on purpose: never hard-requires fields (old mobile builds still
     * save partial addresses); strict validation lives in AddressRequest and
     * the complete-on-use gate.
     */
    public static function sanitizePayload(array $raw): array
    {
        $fields = Arr::only($raw, self::TABLE_KEYS);

        if (isset($fields['address']) && is_array($fields['address'])) {
            $inner = $fields['address'];
            // Map the common legacy aliases onto canonical keys before whitelisting.
            $inner['zip'] = $inner['zip'] ?? $inner['pincode'] ?? $inner['postal_code'] ?? null;
            $inner['house_no'] = $inner['house_no'] ?? $inner['apartment'] ?? $inner['flat_no'] ?? null;
            $inner = Arr::only($inner, self::JSON_KEYS);
            if (isset($inner['zip'])) {
                $inner['zip'] = preg_replace('/\s+/', '', (string) $inner['zip']);
            }
            // Canonical city/district. Every address write funnels through here, so this is the
            // one place that has to know Google answers "which city?" with "South Delhi". It only
            // ever rewrites what it can resolve from the location master — unrecognised input is
            // left exactly as the customer typed it.
            //
            // Fail-soft on purpose. This method is called from contexts with no booted container
            // (it is a static on a model, and the unit tests call it directly), and tidying up a
            // city name must never be the reason someone cannot save their address.
            try {
                $inner = app(\Marvel\Services\LocationNormalizer::class)->normalizeAddressJson($inner);
            } catch (\Throwable $e) {
                // keep the customer's own words
            }
            $fields['address'] = array_filter($inner, fn ($v) => $v !== null);
        }

        if (array_key_exists('default', $fields)) {
            $fields['default'] = filter_var($fields['default'], FILTER_VALIDATE_BOOLEAN);
        }
        foreach (['latitude', 'longitude'] as $coord) {
            if (isset($fields[$coord]) && !is_numeric($fields[$coord])) {
                unset($fields[$coord]);
            }
        }
        if (isset($fields['address_type']) && !in_array($fields['address_type'], ['home', 'office', 'other'], true)) {
            unset($fields['address_type']);
        }

        return $fields;
    }

    /**
     * Complete-on-use gate: which required fields is this address (or order
     * address snapshot) missing? Tolerates the snapshot double-nesting
     * ({address:{...}} vs flat) and the legacy zip aliases.
     *
     * Gate = street + city + state + valid 6-digit zip. house_no is required
     * on NEW address forms but deliberately NOT here — gating legacy rows on
     * it would flag nearly every saved address. country is defaulted, never
     * gated (domestic-only ops).
     */
    public static function missingFields(?array $addr): array
    {
        $addr = $addr ?? [];
        $inner = is_array($addr['address'] ?? null) ? $addr['address'] : $addr;

        $missing = [];
        foreach (['street_address', 'city', 'state'] as $key) {
            if (trim((string) ($inner[$key] ?? '')) === '') {
                $missing[] = $key;
            }
        }
        $zip = $inner['zip'] ?? $inner['pincode'] ?? $inner['postal_code'] ?? null;
        if (!preg_match('/^[1-9][0-9]{5}$/', preg_replace('/\s+/', '', (string) $zip))) {
            $missing[] = 'zip';
        }

        return $missing;
    }

    /** Make this row the customer's only default (call after save). */
    public function makeSoleDefault(): void
    {
        static::where('customer_id', $this->customer_id)
            ->where('id', '!=', $this->id)
            ->where('default', true)
            ->update(['default' => false]);
    }

    /**
     * @return BelongsTo
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }
}
