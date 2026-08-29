<?php

namespace Tests\Feature\Legal;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Legal governance harness: sqlite :memory: running the REAL migration file
 * (no hand-mirrored schema to drift), plus a minimal users table and stub
 * actors whose permissions are plain arrays — LegalWorkflow only calls
 * hasPermissionTo()/hasRole(), so a stub keeps Spatie out of the suite.
 */
abstract class LegalTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');

        $migration = require base_path('packages/marvel/database/migrations/2026_08_30_000100_create_legal_governance_tables.php');
        $migration->up();

        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('name')->nullable();
            });
        }
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->json('options');
                $t->string('language')->default('en');
                $t->timestamps();
            });
            DB::table('settings')->insert([
                'options' => json_encode(['siteTitle' => 'PlantAtHome']),
                'language' => 'en',
            ]);
        }

        DB::table('legal_document_types')->insert([
            ['name' => 'Policy', 'code_prefix' => 'POL', 'sort_order' => 1, 'is_active' => 1],
            ['name' => 'SOP', 'code_prefix' => 'SOP', 'sort_order' => 2, 'is_active' => 1],
        ]);
        DB::table('legal_categories')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Customer Policies', 'slug' => 'customer-policies',
            'sort_order' => 1, 'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Actor stub with a fixed permission set (super_admin bypass NOT granted). */
    protected function actor(array $permissions, int $id = 7): object
    {
        DB::table('users')->insertOrIgnore(['id' => $id, 'name' => "user-{$id}"]);

        return new class($permissions, $id)
        {
            public function __construct(private array $permissions, public int $id)
            {
            }

            public function hasPermissionTo($permission): bool
            {
                return in_array($permission, $this->permissions, true);
            }

            public function hasRole($role): bool
            {
                return false;
            }
        };
    }

    protected function fullActor(int $id = 7): object
    {
        return $this->actor(
            ['legal.view', 'legal.create', 'legal.edit', 'legal.delete', 'legal.review', 'legal.approve', 'legal.publish', 'legal.archive', 'legal.export', 'legal.manage'],
            $id,
        );
    }
}
