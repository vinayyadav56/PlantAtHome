<?php

namespace App\Modules\Serviceability\Application;

use App\Modules\Serviceability\Infrastructure\Models\Country;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Imports the pincode master from the bundled GeoNames-derived CSV (plain or
 * .gz) into postal_codes — the single code path shared by GeoMasterSeeder and
 * plantathome:pincodes-import. Idempotent: chunked upserts keyed on
 * (country_id, pincode). ABORTS (transaction rollback) on any dataset state
 * that maps to no canonical `states` row — a wrong state poisons every
 * coverage rule downstream.
 */
class PostalCodeImporter
{
    private const CHUNK = 1000;

    /** @var array<string,int>|null lower(canonical state name) => states.id */
    private ?array $statesByLower = null;

    /** @var array<string,string> dataset state name => canonical states.name */
    private ?array $stateNameMap = null;

    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public static function defaultDatasetPath(): string
    {
        return __DIR__.'/../Database/data/india_postal_codes.csv.gz';
    }

    /**
     * @return array{pincodes:int, unique_pincodes:int, skipped:int, districts:int}
     */
    public function import(?string $path = null, bool $fresh = false): array
    {
        $path = $path ?: self::defaultDatasetPath();
        if (! is_file($path)) {
            throw new RuntimeException("Postal-code dataset not found: {$path}");
        }

        $report = $this->db->transaction(function () use ($path, $fresh) {
            $country = Country::firstOrCreate(
                ['iso2' => 'IN'],
                ['name' => 'India', 'iso3' => 'IND', 'phone_code' => '+91', 'is_active' => true],
            );

            if ($fresh) {
                $this->db->table('postal_codes')->where('country_id', $country->id)->delete();
            }

            $now = Carbon::now();
            $districtIds = []; // "stateId|lower(name)" => districts.id
            $processed = 0;
            $skipped = 0;
            $buffer = [];

            foreach ($this->rows($path) as $row) {
                $pincode = trim((string) ($row['pincode'] ?? ''));
                if (! preg_match('/^\d{6}$/', $pincode)) {
                    $skipped++;
                    continue;
                }

                $stateId = $this->resolveStateId((string) $row['state']);
                $districtId = $this->resolveDistrictId($districtIds, $stateId, (string) $row['district'], $now);

                $buffer[] = [
                    'country_id'  => $country->id,
                    'state_id'    => $stateId,
                    'district_id' => $districtId,
                    'pincode'     => $pincode,
                    'office_name' => ($row['office_name'] ?? '') !== '' ? mb_substr($row['office_name'], 0, 150) : null,
                    'offices'     => ($row['offices'] ?? '') !== '' ? $row['offices'] : null,
                    'latitude'    => ($row['latitude'] ?? '') !== '' ? $row['latitude'] : null,
                    'longitude'   => ($row['longitude'] ?? '') !== '' ? $row['longitude'] : null,
                    'status'      => 'active',
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
                $processed++;

                if (count($buffer) >= self::CHUNK) {
                    $this->flush($buffer);
                    $buffer = [];
                }
            }
            $this->flush($buffer);

            return [
                'pincodes'        => $processed,
                'unique_pincodes' => (int) $this->db->table('postal_codes')->where('country_id', $country->id)->count(),
                'skipped'         => $skipped,
                'districts'       => count($districtIds),
            ];
        });

        Cache::increment('geo:ver'); // geo list caches refresh

        return $report;
    }

    /**
     * Dataset state name → states.id, via state_name_map.php then exact
     * (case-insensitive) match. Shared with GeoMasterSeeder's district import.
     */
    public function resolveStateId(string $datasetState): int
    {
        $this->stateNameMap ??= require __DIR__.'/../Database/data/state_name_map.php';
        if ($this->statesByLower === null) {
            $this->statesByLower = [];
            foreach ($this->db->table('states')->pluck('id', 'name') as $name => $id) {
                $this->statesByLower[mb_strtolower(trim((string) $name))] = (int) $id;
            }
        }

        $name = trim($datasetState);
        $canonical = $this->stateNameMap[$name] ?? $name;
        $id = $this->statesByLower[mb_strtolower($canonical)] ?? null;
        if ($id === null) {
            throw new RuntimeException(
                "Unmapped state '{$name}' — add it to Serviceability/Database/data/state_name_map.php or seed it into `states`.",
            );
        }

        return $id;
    }

    /** @param array<string,int> $cache */
    private function resolveDistrictId(array &$cache, int $stateId, string $district, Carbon $now): int
    {
        $district = trim($district);
        if ($district === '') {
            throw new RuntimeException('Postal-code row without a district.');
        }
        $key = $stateId.'|'.mb_strtolower($district);
        if (! isset($cache[$key])) {
            $existing = $this->db->table('districts')
                ->where('state_id', $stateId)->where('name', $district)->value('id');
            $cache[$key] = (int) ($existing ?? $this->db->table('districts')->insertGetId([
                'state_id' => $stateId, 'name' => $district, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]));
        }

        return $cache[$key];
    }

    private function flush(array $buffer): void
    {
        if ($buffer === []) {
            return;
        }
        $this->db->table('postal_codes')->upsert(
            $buffer,
            ['country_id', 'pincode'],
            ['state_id', 'district_id', 'office_name', 'offices', 'latitude', 'longitude', 'status', 'updated_at'],
        );
    }

    /**
     * Lazily stream CSV rows (plain or gzip) as assoc arrays keyed by header.
     *
     * @return \Generator<array<string,string>>
     */
    private function rows(string $path): \Generator
    {
        $gz = str_ends_with($path, '.gz');
        $handle = $gz ? gzopen($path, 'rb') : fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open dataset: {$path}");
        }

        try {
            $header = null;
            while (($line = $gz ? gzgets($handle) : fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '') {
                    continue;
                }
                $fields = str_getcsv($line, ',', '"', '\\');
                if ($header === null) {
                    $header = $fields;
                    continue;
                }
                yield array_combine($header, array_pad($fields, count($header), ''));
            }
        } finally {
            $gz ? gzclose($handle) : fclose($handle);
        }
    }
}
