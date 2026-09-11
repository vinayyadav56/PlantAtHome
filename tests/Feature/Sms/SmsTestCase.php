<?php

namespace Tests\Feature\Sms;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DLT SMS harness (Legal pattern): sqlite :memory: running the REAL email
 * engine migration + the REAL sms-channel migration, so the schema under test
 * is the deployed schema, not a hand mirror.
 */
abstract class SmsTestCase extends TestCase
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

        foreach ([
            'packages/marvel/database/migrations/2026_08_05_000000_create_email_engine_tables.php',
            'packages/marvel/database/migrations/2026_09_11_000000_add_sms_channel_to_email_templates.php',
        ] as $file) {
            $migration = require base_path($file);
            $migration->up();
        }
    }

    protected function activate(string $code, string $flowId = 'FLOW123'): void
    {
        DB::table('email_templates')->where('template_code', $code)->update([
            'status' => 'active',
            'provider_template_id' => $flowId,
        ]);
    }
}
