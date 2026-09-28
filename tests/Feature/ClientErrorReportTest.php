<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * POST /api/client-errors — the storefront's error boundaries report here. The contract that
 * matters: it always answers 200 (a reporter that errors teaches the client to stop reporting),
 * it lands one row in request_log_exceptions so the existing viewer shows it, and the full
 * payload — stack and the persisted checkout state — goes to the application log.
 */
final class ClientErrorReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');

        Schema::create('request_log_exceptions', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->char('request_id', 26)->nullable();
            $t->string('class', 191);
            $t->string('message', 500)->nullable();
            $t->string('file', 255)->nullable();
            $t->integer('line')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('path', 255)->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function test_a_report_lands_in_the_viewer_table_and_the_log_with_its_stack(): void
    {
        Log::spy();

        $res = $this->postJson('/api/client-errors', [
            'source'         => 'route-boundary',
            'message'        => "Cannot read properties of undefined (reading 'includes')",
            'stack'          => "TypeError: Cannot read properties of undefined (reading 'includes')\n    at VerifiedItemList (verified-item-list.tsx:52:41)",
            'url'            => 'https://plantathome.in/checkout',
            'checkout_state' => '{"billing_address":null,"shipping_address":{"address":{"zip":"110001"}}}',
        ]);

        $res->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('request_log_exceptions', [
            'class' => 'ClientError:route-boundary',
            'file'  => 'https://plantathome.in/checkout',
            'path'  => '/client-errors',
        ]);

        // The stack and the persisted state are the whole point — they are in the log, not the row.
        Log::shouldHaveReceived('warning')->withArgs(function ($msg, $ctx) {
            return $msg === 'client.error'
                && str_contains($ctx['stack'] ?? '', 'verified-item-list.tsx:52:41')
                && str_contains($ctx['checkout_state'] ?? '', '110001');
        })->once();
    }

    public function test_it_never_fails_the_caller_even_on_garbage(): void
    {
        $this->postJson('/api/client-errors', ['message' => str_repeat('x', 20000), 'stack' => ['not' => 'a string']])
            ->assertOk();
        $this->postJson('/api/client-errors', [])->assertOk();
    }
}
