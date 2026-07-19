<?php

namespace Tests\Feature\MarketingAutomation;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Identity\IdentityTestCase;

/**
 * Base for the Marketing Automation module tests: Identity setup (sqlite + RBAC +
 * demo users) plus the marketing_* tables and a small demo `people` table for
 * audience SQL to query. Sends are disabled (dry-run) so the queue pipeline runs
 * end-to-end without touching a real email/SMS/WhatsApp provider.
 */
abstract class MarketingAutomationTestCase extends IdentityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'marketing.dispatch_enabled' => false,
            'queue.default'              => 'sync', // run jobs inline in tests
        ]);

        (require base_path('app/Modules/Marketing/Database/Migrations/2026_07_19_000100_create_marketing_tables.php'))->up();

        Schema::create('demo_people', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name');
            $t->string('email')->nullable();
            $t->string('phone')->nullable();
            $t->string('city')->nullable();
            $t->boolean('newsletter')->default(true);
        });

        DB::table('demo_people')->insert([
            ['name' => 'Asha',    'email' => 'asha@example.com',   'phone' => '9000000001', 'city' => 'Delhi',  'newsletter' => true],
            ['name' => 'Bhavna',  'email' => 'bhavna@example.com', 'phone' => '9000000002', 'city' => 'Delhi',  'newsletter' => true],
            ['name' => 'Chetan',  'email' => 'chetan@example.com', 'phone' => '9000000003', 'city' => 'Mumbai', 'newsletter' => true],
            ['name' => 'NoEmail', 'email' => null,                 'phone' => '9000000004', 'city' => 'Delhi',  'newsletter' => true],
        ]);
    }

    protected function adminHeaders(): array
    {
        return $this->bearer($this->accessToken('superadmin@plantathome.test'));
    }
}
