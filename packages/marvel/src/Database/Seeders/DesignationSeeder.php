<?php

namespace Marvel\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Marvel\Database\Models\Designation;
use Marvel\Enums\ModulePermission;

/**
 * Phase B — seeds the example designations from the enterprise spec, each with a
 * sensible default module-permission set. Idempotent (updateOrCreate by slug) and
 * additive — re-running on deploy refreshes the canonical defaults without
 * touching admin-created designations.
 */
class DesignationSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->designations() as $order => $d) {
            $defaults = array_values(array_filter(
                $d['permissions'],
                fn ($p) => ModulePermission::isValid($p)
            ));

            Designation::updateOrCreate(
                ['slug' => Str::slug($d['name'])],
                [
                    'name'                => $d['name'],
                    'department'          => $d['department'],
                    'description'         => $d['description'] ?? null,
                    'default_permissions' => $defaults,
                    'is_active'           => true,
                    'display_order'       => $order,
                ]
            );
        }
    }

    private function designations(): array
    {
        $f = fn (string $module, array $actions) => ModulePermission::forModule($module, $actions);

        return [
            [
                'name' => 'Customer Care Executive',
                'department' => 'Support',
                'description' => 'Handles customer queries, orders and support.',
                'permissions' => array_merge(
                    $f('dashboard', ['view']),
                    $f('orders', ['view', 'edit']),
                    $f('customers', ['view', 'edit']),
                    $f('kanban', ['view', 'edit']),
                ),
            ],
            [
                'name' => 'Operations Executive',
                'department' => 'Operations',
                'description' => 'Day-to-day order operations and fulfilment.',
                'permissions' => array_merge(
                    $f('dashboard', ['view']),
                    $f('orders', ['view', 'create', 'edit']),
                    $f('customers', ['view']),
                    $f('products', ['view']),
                    $f('kanban', ['view', 'edit']),
                ),
            ],
            [
                'name' => 'Marketing Executive',
                'department' => 'Marketing',
                'description' => 'Campaigns, promotions and merchandising.',
                'permissions' => array_merge(
                    $f('dashboard', ['view']),
                    $f('products', ['view', 'edit']),
                    $f('reports', ['view']),
                ),
            ],
            [
                'name' => 'Inventory Manager',
                'department' => 'Inventory',
                'description' => 'Stock, catalog and bundle management.',
                'permissions' => array_merge(
                    $f('dashboard', ['view']),
                    $f('products', ['view', 'create', 'edit', 'delete']),
                    $f('bundles', ['view', 'create', 'edit', 'delete']),
                    $f('reports', ['view']),
                ),
            ],
            [
                'name' => 'Vendor Manager',
                'department' => 'Marketplace',
                'description' => 'Vendor onboarding, inventory and payouts.',
                'permissions' => array_merge(
                    $f('dashboard', ['view']),
                    $f('vendors', ['view', 'create', 'edit']),
                    $f('products', ['view']),
                    $f('reports', ['view']),
                ),
            ],
            [
                'name' => 'Finance Executive',
                'department' => 'Finance',
                'description' => 'Transactions, settlements and finance reports.',
                'permissions' => array_merge(
                    $f('dashboard', ['view']),
                    $f('orders', ['view']),
                    $f('vendors', ['view']),
                    $f('reports', ['view']),
                ),
            ],
            [
                'name' => 'Regional Manager',
                'department' => 'Operations',
                'description' => 'Regional oversight across orders, vendors and reports.',
                'permissions' => array_merge(
                    $f('dashboard', ['view']),
                    $f('orders', ['view', 'edit']),
                    $f('customers', ['view']),
                    $f('vendors', ['view']),
                    $f('products', ['view']),
                    $f('reports', ['view']),
                    $f('kanban', ['view', 'edit']),
                ),
            ],
        ];
    }
}
