<?php

namespace Marvel\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\City;
use Marvel\Database\Models\State;

/**
 * Addressable India cities — the master list that powers the State→City dropdowns
 * on every address form (admin + shop). Seeds ~1,600 major cities/towns/districts
 * from packages/marvel/data/india-cities.json, keyed to the states LocationSeeder
 * already created.
 *
 * These are seeded as is_serviceable = FALSE: they exist so a customer/vendor can
 * PICK their city on an address, but they are NOT PlantAtHome delivery cities — the
 * serviceable set stays exactly what LocationSeeder derives from delivery_pincodes
 * (and what admins toggle). Idempotent + additive: only missing (name, state_id)
 * pairs are inserted, so an existing serviceable city is never touched/downgraded.
 * Runs at the tail of LocationSeeder so states + serviceable cities exist first.
 */
class IndiaCitySeeder extends Seeder
{
    protected function dataPath(): string
    {
        return base_path('packages/marvel/data/india-cities.json');
    }

    public function run(): void
    {
        $path = $this->dataPath();
        if (!is_file($path)) {
            $this->command?->error("[IndiaCitySeeder] india-cities.json not found at {$path}");
            return;
        }
        $rows = json_decode((string) file_get_contents($path), true);
        if (!is_array($rows) || empty($rows)) {
            $this->command?->error('[IndiaCitySeeder] india-cities.json is empty or malformed.');
            return;
        }

        // state name (lowercased) → id
        $stateIdByName = [];
        foreach (State::get(['id', 'name']) as $s) {
            $stateIdByName[strtolower(trim($s->name))] = (int) $s->id;
        }

        // Existing (name|state_id) keys so we only INSERT what's missing — never
        // update, so a serviceable row keeps is_serviceable = 1.
        $existing = [];
        foreach (City::get(['name', 'state_id']) as $c) {
            $existing[strtolower(trim($c->name)) . '|' . ((int) $c->state_id)] = true;
        }

        $now = now();
        $batch = [];
        $inserted = 0;
        $skippedState = 0;
        $seenThisRun = [];

        foreach ($rows as $row) {
            $cityName = trim((string) ($row['city'] ?? ''));
            $stateName = trim((string) ($row['state'] ?? ''));
            if ($cityName === '' || $stateName === '') {
                continue;
            }
            $stateId = $stateIdByName[strtolower($stateName)] ?? null;
            if ($stateId === null) {
                $skippedState++;
                continue; // no matching state row — skip rather than orphan
            }
            $key = strtolower($cityName) . '|' . $stateId;
            if (isset($existing[$key]) || isset($seenThisRun[$key])) {
                continue;
            }
            $seenThisRun[$key] = true;
            $batch[] = [
                'name'           => $cityName,
                'state_id'       => $stateId,
                'state_name'     => $stateName,
                'status'         => City::STATUS_ACTIVE,
                'is_serviceable' => false,
                'display_order'  => 0,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
            if (count($batch) >= 500) {
                City::insert($batch);
                $inserted += count($batch);
                $batch = [];
            }
        }
        if (!empty($batch)) {
            City::insert($batch);
            $inserted += count($batch);
        }

        $this->command?->info("[IndiaCitySeeder] inserted {$inserted} addressable cities" . ($skippedState ? " ({$skippedState} rows skipped — unknown state)" : ''));
    }
}
