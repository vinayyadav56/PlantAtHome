<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use Marvel\Enums\ModuleCatalog;
use Marvel\Enums\ModulePermission;
use Tests\TestCase;

/**
 * The refund permission surface, and the backward-compatibility rule that makes adding to
 * ModuleCatalog safe.
 *
 * Permission strings are persisted in Spatie tables and assigned to real roles, so a RENAME is
 * not a refactor — it silently revokes access for everyone holding the old string. The catalogue
 * encodes that rule (2 segments when the submodule is the module's default, 3 otherwise) and
 * this pins it for the entries that existed before refunds were added.
 */
final class RefundPermissionsTest extends TestCase
{
    public function test_refund_permissions_follow_the_naming_convention(): void
    {
        $all = ModulePermission::all();

        // default submodule ⇒ 2 segments; named submodule ⇒ 3
        $this->assertContains('refunds.view', $all);
        $this->assertContains('refunds.approve', $all);
        $this->assertContains('refunds.payout', $all);
        $this->assertContains('refunds.returns.receive', $all);
        $this->assertContains('refunds.policies.edit', $all);

        $this->assertNotContains('refunds.refunds.view', $all, 'the default submodule must collapse to 2 segments');
    }

    /**
     * Deciding a refund is owed and moving the money are different acts, and only the second is
     * irreversible. They must not collapse into one grant.
     */
    public function test_approving_and_paying_out_are_separate_permissions(): void
    {
        $this->assertNotSame(
            ModuleCatalog::permissionName('refunds', 'refunds', 'approve'),
            ModuleCatalog::permissionName('refunds', 'refunds', 'payout')
        );
    }

    /**
     * Adding a module must not disturb what already exists — every one of these is assigned to
     * live roles today.
     */
    public function test_existing_permission_strings_are_untouched(): void
    {
        $all = ModulePermission::all();

        foreach ([
            'orders.view', 'orders.edit', 'orders.export',
            'orders.assignment.assign', 'orders.tracking.update',
            'accounting.view', 'accounting.approve', 'accounting.settlements.pay',
            'settings.integrations.view',
        ] as $existing) {
            $this->assertContains($existing, $all, "{$existing} must keep working");
        }
    }

    public function test_every_catalogue_entry_is_a_valid_permission(): void
    {
        foreach (ModulePermission::all() as $name) {
            $this->assertTrue(ModulePermission::isValid($name), "{$name} is not recognised by isValid()");
        }
    }
}
