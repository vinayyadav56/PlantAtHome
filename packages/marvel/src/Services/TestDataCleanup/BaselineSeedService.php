<?php

namespace Marvel\Services\TestDataCleanup;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Restores the reference data a cleaned environment needs to function, by composing the
 * seeders that already exist and are already idempotent (upsert by slug / firstOrCreate).
 * Nothing new is invented here — re-running is always safe.
 */
class BaselineSeedService
{
    /** group => [label, seeder classes in dependency order] */
    public const GROUPS = [
        'core' => ['Settings, roles & permissions, designations', [
            \Marvel\Database\Seeders\SettingsSeeder::class,
            \Marvel\Database\Seeders\RolePermissionSeeder::class,
            \Marvel\Database\Seeders\DesignationSeeder::class,
        ]],
        'taxonomy' => ['Plant categories, attributes & collections', [
            \Marvel\Database\Seeders\PlantTaxonomySeeder::class,
            \Marvel\Database\Seeders\PlantAttributeDefinitionSeeder::class,
            \Marvel\Database\Seeders\PlantCollectionSeeder::class,
        ]],
        'locations' => ['Cities and service availability', [
            \Marvel\Database\Seeders\IndiaCitySeeder::class,
        ]],
        'content' => ['Email templates, refund reasons/policies, FAQs, terms', [
            \Marvel\Database\Seeders\EmailEngineSeeder::class,
            \Marvel\Database\Seeders\RefundReasonSeeder::class,
            \Marvel\Database\Seeders\RefundPolicySeeder::class,
        ]],
    ];

    public function run(array $groups): array
    {
        $results = [];
        foreach ($groups as $group) {
            [$label, $seeders] = self::GROUPS[$group] ?? [null, []];
            if (!$seeders) {
                continue;
            }
            foreach ($seeders as $class) {
                if (!class_exists($class)) {
                    $results[$group][] = ['seeder' => class_basename($class), 'status' => 'missing'];
                    continue;
                }
                try {
                    Artisan::call('db:seed', ['--class' => $class, '--force' => true]);
                    $results[$group][] = ['seeder' => class_basename($class), 'status' => 'ok'];
                } catch (\Throwable $e) {
                    Log::warning('baseline seed failed', ['class' => $class, 'error' => $e->getMessage()]);
                    $results[$group][] = ['seeder' => class_basename($class), 'status' => 'failed', 'error' => $e->getMessage()];
                }
            }
        }
        return $results;
    }
}
