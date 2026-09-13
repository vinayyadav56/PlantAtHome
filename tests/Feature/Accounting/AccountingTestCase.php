<?php

namespace Tests\Feature\Accounting;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Seeders\AccountingSeeder;
use Tests\TestCase;

/**
 * Accounting harness (SmsTestCase pattern): sqlite :memory: running the REAL accounting
 * migration so the decimal precision and the UNIQUE idempotency index under test are the
 * deployed ones, not a hand mirror. Seeds the real chart of accounts.
 */
abstract class AccountingTestCase extends TestCase
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

        foreach ($this->migrations() as $file) {
            $migration = require base_path($file);
            $migration->up();
        }

        // settings row so AccountingConfig / Settings::getData() have something to read
        if (!Schema::hasTable('settings')) {
            Schema::create('settings', function ($t) {
                $t->id();
                $t->json('options')->nullable();
                $t->string('language')->default('en');
                $t->timestamps();
            });
            DB::table('settings')->insert(['options' => json_encode(['accounting' => ['enabled' => true]]), 'language' => 'en']);
        }

        (new AccountingSeeder())->run();
    }

    /** @return string[] migration files (relative to base_path) to run, in order */
    protected function migrations(): array
    {
        return ['packages/marvel/database/migrations/2026_09_14_000000_create_accounting_foundation_tables.php'];
    }
}
