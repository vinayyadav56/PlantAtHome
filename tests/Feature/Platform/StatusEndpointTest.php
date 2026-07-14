<?php

namespace Tests\Feature\Platform;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Identity\IdentityTestCase;

/**
 * v2 Phase 12 (observability): GET /api/v1/platform/status — admin-gated ops
 * status. Reuses the Identity sqlite harness (users + JWT) and adds the
 * observability tables the endpoint reads.
 */
class StatusEndpointTest extends IdentityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('platform_heartbeats', function (Blueprint $t) {
            $t->string('name', 64)->primary();
            $t->timestamp('beat_at');
        });
        Schema::create('jobs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('queue');
            $t->longText('payload');
            $t->unsignedTinyInteger('attempts');
            $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at');
            $t->unsignedInteger('created_at');
        });
        Schema::create('failed_jobs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('uuid')->unique();
            $t->text('connection');
            $t->text('queue');
            $t->longText('payload');
            $t->longText('exception');
            $t->timestamp('failed_at')->useCurrent();
        });
    }

    public function test_guests_and_non_admins_are_rejected(): void
    {
        $this->getJson('/api/v1/platform/status')->assertStatus(401);

        $ownerToken = $this->accessToken('owner.a@plantathome.test');
        $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->getJson('/api/v1/platform/status')
            ->assertStatus(403);
    }

    public function test_admin_sees_healthy_status_with_fresh_heartbeat(): void
    {
        DB::table('platform_heartbeats')->insert(['name' => 'scheduler', 'beat_at' => now()]);

        $token = $this->accessToken('admin@plantathome.test');
        $res = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/platform/status');

        $res->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'healthy',
                    'outbox' => ['pending', 'failed', 'oldest_pending_age_seconds', 'flowing'],
                    'queue' => ['depth', 'failed_jobs_24h'],
                    'scheduler' => ['last_beat_at', 'beat_age_seconds', 'alive'],
                ],
            ])
            ->assertJsonPath('data.healthy', true)
            ->assertJsonPath('data.scheduler.alive', true)
            ->assertJsonPath('data.queue.depth', 0);
    }

    public function test_stale_heartbeat_flips_healthy_false(): void
    {
        DB::table('platform_heartbeats')->insert([
            'name' => 'scheduler',
            'beat_at' => now()->subMinutes(10),
        ]);

        $token = $this->accessToken('admin@plantathome.test');
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/platform/status')
            ->assertStatus(200)
            ->assertJsonPath('data.healthy', false)
            ->assertJsonPath('data.scheduler.alive', false);
    }

    public function test_missing_heartbeat_reports_dead_scheduler(): void
    {
        $token = $this->accessToken('admin@plantathome.test');
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/platform/status')
            ->assertStatus(200)
            ->assertJsonPath('data.healthy', false)
            ->assertJsonPath('data.scheduler.beat_age_seconds', null);
    }
}
