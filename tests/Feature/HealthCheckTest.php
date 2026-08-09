<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Liveness vs readiness. A load balancer needs to tell "the process answered" apart from
 * "this instance can actually complete a request", so a transient dependency blip drains an
 * instance instead of killing a healthy one.
 */
final class HealthCheckTest extends TestCase
{
    /** Liveness is dependency-free — always 200 while PHP is serving. */
    public function test_liveness_is_ok_and_has_no_dependencies(): void
    {
        $this->getJson('/api/health/live')
            ->assertOk()
            ->assertJson(['status' => 'ok']);
    }

    /** Readiness reports each dependency and uses 200/503 so the LB can act on it. */
    public function test_readiness_reports_dependency_checks(): void
    {
        $res = $this->getJson('/api/health/ready');

        $res->assertJsonStructure(['status', 'checks' => ['db', 'cache']]);
        $this->assertContains($res->getStatusCode(), [200, 503]);

        $body = $res->json();
        // status and code must agree.
        if ($body['status'] === 'ready') {
            $this->assertSame(200, $res->getStatusCode());
            $this->assertNotContains('fail', $body['checks']);
        } else {
            $this->assertSame(503, $res->getStatusCode());
        }
    }

    /** The legacy endpoint keeps its shape so existing monitors don't break. */
    public function test_legacy_health_shape_is_unchanged(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonStructure(['status', 'db', 'env']);
    }
}
