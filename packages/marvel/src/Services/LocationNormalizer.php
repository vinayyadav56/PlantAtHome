<?php

namespace Marvel\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The one place that decides what a canonical CITY is, and what is merely a DISTRICT of one.
 *
 * The problem this solves: Google, IP geolocation and India Post all answer the question "what
 * city is this?" with an administrative district. For Delhi they return "South Delhi", "New
 * Delhi", "North Delhi" — nine districts and a New-Delhi-that-is-not-a-city. Stored verbatim,
 * each behaves like a separate city: the catalogue splits, the shopping-city gate mismatches, and
 * the dashboard reports Delhi three times.
 *
 * The rule, in one line: **a city is what we ship to; a district is where inside it you live.**
 *
 *   India > Delhi (state) > Delhi (city) > South Delhi (district) > 110017 > Saket
 *
 * Resolution order, most authoritative first — we never guess:
 *
 *   1. PINCODE. `postal_codes` maps a pin to its district and city (19,238 rows seeded). This is
 *      the only input that is unambiguous, so it wins outright.
 *   2. CITY ROW. A name that resolves to a `cities` row which is flagged `is_subdivision` walks up
 *      to its `parent_city_id`, and the subdivision's own name becomes the district.
 *   3. ALIAS TABLE. Name-only input with no matching row falls back to
 *      AvailabilityService::aliasMap() — the historical spellings (Gurgaon/Bombay/…).
 *   4. NOTHING. Unrecognised input passes through untouched. A wrong guess about where someone
 *      lives is worse than leaving their own words alone.
 *
 * ⚠️ This returns DISPLAY NAMES ("Delhi"). AvailabilityService::canonicalCityKey() returns a
 * lowercase KEY ("delhi") and is what the ~30 catalogue/pricing call sites use. Both exist on
 * purpose: the key is a join value, this is what a human reads on an address.
 */
class LocationNormalizer
{
    /**
     * Directional prefixes that mark an administrative slice of a bigger city, and the suffixes
     * that do the same job ("Mumbai City", "Mumbai Suburban"). Longest first — "north east" has to
     * be tried before "north" or it strips to "East Delhi" and finds the wrong parent.
     */
    private const SUBDIVISION_PREFIXES = [
        'north east', 'north west', 'south east', 'south west',
        'north', 'south', 'east', 'west', 'central', 'new',
    ];

    private const SUBDIVISION_SUFFIXES = ['city', 'suburban', 'urban', 'rural'];

    /** Normalised comparison key: lowercase, trimmed, internal whitespace collapsed. */
    public static function key(?string $s): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', (string) $s)));
    }

    /**
     * Parent-name candidates for a possible subdivision, best first. Returns [] when the name has
     * no administrative qualifier at all.
     *
     * This is deliberately only half the test — a candidate is a real subdivision ONLY if the
     * parent it names is itself a city we serve (see resolveSubdivisions). Without that second
     * half the rule eats genuine standalone districts: "East Siang", "North Lakhimpur" and
     * "South West Garo Hills" all look like subdivisions and are not.
     *
     * @return string[]
     */
    public static function parentNameCandidates(string $name): array
    {
        $n = self::key($name);
        $out = [];

        foreach (self::SUBDIVISION_PREFIXES as $p) {
            if (str_starts_with($n, $p . ' ')) {
                $rest = trim(substr($n, strlen($p) + 1));
                if ($rest !== '') {
                    $out[] = $rest;
                }
            }
        }
        foreach (self::SUBDIVISION_SUFFIXES as $s) {
            if (str_ends_with($n, ' ' . $s)) {
                $rest = trim(substr($n, 0, -(strlen($s) + 1)));
                if ($rest !== '') {
                    $out[] = $rest;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Given every city row in one state, decide which are subdivisions of which.
     *
     * Pure function over arrays so the migration that stamps `is_subdivision` and this service
     * can never disagree about the rule.
     *
     * @param  array<int,array{id:int,name:string,is_serviceable:bool|int}>  $rows
     * @return array<int,int> subdivision city id => parent city id
     */
    public static function resolveSubdivisions(array $rows): array
    {
        $byKey = [];
        foreach ($rows as $r) {
            $byKey[self::key($r['name'])] = $r;
        }

        $map = [];
        foreach ($rows as $r) {
            foreach (self::parentNameCandidates($r['name']) as $candidate) {
                $parent = $byKey[$candidate] ?? null;
                // The second half of the test: only a city we actually SHIP TO can own
                // subdivisions. This is what keeps "East Siang" (parent "Siang", not a shopping
                // city) a city in its own right, while "South Delhi" becomes a district of Delhi.
                if ($parent && (int) $parent['id'] !== (int) $r['id'] && (bool) $parent['is_serviceable']) {
                    $map[(int) $r['id']] = (int) $parent['id'];
                    break; // candidates are ordered best-first
                }
            }
        }

        return $map;
    }

    /**
     * Normalize a loose address fragment into the canonical hierarchy.
     *
     * @param  array{city?:string|null,state?:string|null,district?:string|null,zip?:string|null,pincode?:string|null}  $in
     * @return array{city:?string,city_id:?int,district:?string,district_id:?int,state:?string,state_id:?int,pincode:?string}
     */
    public function normalize(array $in): array
    {
        // What the caller already told us. Used below to decide whether a resolution is actually an
        // improvement — see preferInput().
        $inputCity = self::clean($in['city'] ?? null);

        $out = [
            'city'        => self::clean($in['city'] ?? null),
            'city_id'     => null,
            'district'    => self::clean($in['district'] ?? null),
            'district_id' => null,
            'state'       => self::clean($in['state'] ?? null),
            'state_id'    => null,
            'pincode'     => self::pincode($in['zip'] ?? $in['pincode'] ?? null),
        ];

        // 1. Pincode is unambiguous — it beats whatever the geocoder called the city.
        if ($out['pincode'] && ($row = $this->byPincode($out['pincode']))) {
            $out['district']    = $row['district'] ?? $out['district'];
            $out['district_id'] = $row['district_id'] ?? null;
            $out['state']       = $row['state'] ?? $out['state'];
            $out['state_id']    = $row['state_id'] ?? null;
            if (!empty($row['city'])) {
                // A promotion (district -> its city) is the answer we want. A same-place rename is
                // not: see preferInput.
                $out['city']    = !empty($row['promoted'])
                    ? $row['city']
                    : self::preferInput($inputCity, $row['city']);
                $out['city_id'] = $row['city_id'];
                return $out;
            }
        }

        // 2/3. Name lookup, then the historical-spelling alias table.
        if ($out['city'] !== null && $out['city'] !== '') {
            $city = $this->cityByName($out['city'], $out['state']);
            if (!$city) {
                $aliased = AvailabilityService::canonicalCityKey($out['city']);
                if ($aliased !== self::key($out['city'])) {
                    $city = $this->cityByName($aliased, $out['state']);
                }
            }
            if ($city) {
                // A subdivision is not a city. Walk to the parent and keep the slice as district —
                // demoting it must never LOSE it, that is the whole point of the split.
                $promoted = false;
                if (!empty($city['parent_city_id']) && ($parent = $this->cityById((int) $city['parent_city_id']))) {
                    if ($out['district'] === null || $out['district'] === '') {
                        $out['district'] = $city['name'];
                    }
                    $out['district_id'] = $out['district_id'] ?: ($city['district_id'] ?? null);
                    $city = $parent;
                    $promoted = true;
                }
                $out['city']     = $promoted ? $city['name'] : self::preferInput($inputCity, $city['name']);
                $out['city_id']  = (int) $city['id'];
                $out['state_id'] = $out['state_id'] ?: ($city['state_id'] ?? null);
                $out['state']    = $out['state'] ?: ($city['state_name'] ?? null);
            }
        }

        // 4. Nothing resolved: the caller's own words stand.
        return $out;
    }

    /**
     * Normalize the inner canonical-address JSON in place (the shape in Address::JSON_KEYS).
     * Returns the array unchanged when nothing resolves, so it is safe on every write path.
     */
    public function normalizeAddressJson(array $inner): array
    {
        $res = $this->normalize([
            'city'     => $inner['city'] ?? null,
            'state'    => $inner['state'] ?? null,
            'district' => $inner['district'] ?? null,
            'zip'      => $inner['zip'] ?? null,
        ]);

        if ($res['city']) {
            $inner['city'] = $res['city'];
        }
        if ($res['state']) {
            $inner['state'] = $res['state'];
        }
        if ($res['district']) {
            $inner['district'] = $res['district'];
        }

        return $inner;
    }

    // ---------------------------------------------------------------- lookups

    /**
     * Keep the caller's spelling when the master's row means the same place.
     *
     * The master is authoritative about WHICH place a pincode is in, not about what that place is
     * called today. Haryana has one row, named "Gurgaon" — the city was renamed Gurugram in 2016 —
     * so resolving a "Gurugram" address by its pincode rewrote it backwards to "Gurgaon". Both
     * spellings share a canonical key, which is precisely the signal that there was nothing to fix.
     *
     * ⚠️ Only for a same-level match. It must NOT be consulted after a subdivision walk: "South
     * Delhi" and "Delhi" also share a canonical key (the alias table maps every NCT district onto
     * delhi), so applying it there would keep the district and undo the promotion entirely.
     */
    private static function preferInput(?string $input, string $resolved): string
    {
        if ($input === null || $input === '') {
            return $resolved;
        }
        return AvailabilityService::canonicalCityKey($input) === AvailabilityService::canonicalCityKey($resolved)
            ? $input
            : $resolved;
    }

    private static function clean(?string $s): ?string
    {
        $s = trim(preg_replace('/\s+/', ' ', (string) $s));
        return $s === '' ? null : $s;
    }

    private static function pincode($raw): ?string
    {
        $p = preg_replace('/\D/', '', (string) $raw);
        return preg_match('/^[1-9][0-9]{5}$/', $p) ? $p : null;
    }

    /**
     * The whole `cities` table, indexed by normalised name. 1,644 rows — small enough that one
     * in-process load beats a per-call query, and it sidesteps the fact that collapsing internal
     * whitespace in SQL needs REGEXP_REPLACE (MySQL 8 only, absent in the SQLite test schemas).
     *
     * ponytail: per-process memo. City data changes rarely; a long-lived worker holds it until
     * restart. Move to a tagged cache if cities ever become operator-editable at speed.
     */
    /** In-process index of the cities table, shared by every instance. */
    private static ?array $cityIndex = null;

    /**
     * Drop the memo. Anything that MUTATES `cities` in the same process — the subdivision
     * migration, the geo seeder — must call this, or it goes on resolving against the table as it
     * looked before it was fixed.
     */
    public static function flush(): void
    {
        self::$cityIndex = null;
    }

    private function index(): array
    {
        if (self::$cityIndex !== null) {
            return self::$cityIndex;
        }
        $index = null;

        $index = ['byKey' => [], 'byId' => []];
        // No database, no location master, no normalization — just the caller's own words. The
        // whole service has to survive being called from a context with no container or no
        // schema, because sanitizePayload sits on the write path of every saved address.
        try {
            if (!Schema::hasTable('cities')) {
                return self::$cityIndex = $index;
            }
        } catch (\Throwable $e) {
            return self::$cityIndex = $index;
        }

        $cols = ['id', 'name', 'state_id'];
        foreach (['state_name', 'parent_city_id', 'district_id', 'is_serviceable'] as $optional) {
            if (Schema::hasColumn('cities', $optional)) {
                $cols[] = $optional;
            }
        }


        try {
            $rows = DB::table('cities')->get($cols);
        } catch (\Throwable $e) {
            return self::$cityIndex = $index;
        }

        foreach ($rows as $row) {
            $r = (array) $row;
            $index['byId'][(int) $r['id']] = $r;
            // First writer wins: a serviceable row must not be shadowed by a same-named one.
            $k = self::key($r['name']);
            if (!isset($index['byKey'][$k]) || !empty($r['is_serviceable'])) {
                $index['byKey'][$k] = $r;
            }
        }

        return self::$cityIndex = $index;
    }

    private function cityById(int $id): ?array
    {
        return $this->index()['byId'][$id] ?? null;
    }

    private function cityByName(string $name, ?string $state = null): ?array
    {
        $row = $this->index()['byKey'][self::key($name)] ?? null;
        if (!$row) {
            return null;
        }
        // A state on the input is a filter, never a hint: "Delhi" the state and "Delhi" the city
        // coexist, and so do same-named cities in different states.
        if ($state !== null && !empty($row['state_name']) && self::key($row['state_name']) !== self::key($state)) {
            return null;
        }
        return $row;
    }

    /** @return array{city:?string,city_id:?int,district:?string,district_id:?int,state:?string,state_id:?int}|null */
    private function byPincode(string $pincode): ?array
    {
        try {
            if (!Schema::hasTable('postal_codes')) {
                return null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        $row = DB::table('postal_codes as p')
            ->leftJoin('districts as d', 'd.id', '=', 'p.district_id')
            ->leftJoin('states as s', 's.id', '=', 'p.state_id')
            ->where('p.pincode', $pincode)
            ->select([
                'p.city_id', 'p.district_id', 'p.state_id',
                'd.name as district_name', 's.name as state_name',
            ])
            ->first();

        if (!$row) {
            return null;
        }

        $city = $row->city_id ? $this->cityById((int) $row->city_id) : null;
        // Belt and braces: postal_codes.city_id pointed at subdivision rows for half of Delhi
        // until the repair migration. Walking the parent here means a stale row still resolves.
        $promoted = false;
        if ($city && !empty($city['parent_city_id'])) {
            $city = $this->cityById((int) $city['parent_city_id']) ?: $city;
            $promoted = true;
        }

        return [
            'city'        => $city['name'] ?? null,
            'city_id'     => isset($city['id']) ? (int) $city['id'] : null,
            'promoted'    => $promoted,
            'district'    => $row->district_name ?: null,
            'district_id' => $row->district_id ? (int) $row->district_id : null,
            'state'       => $row->state_name ?: null,
            'state_id'    => $row->state_id ? (int) $row->state_id : null,
        ];
    }
}
