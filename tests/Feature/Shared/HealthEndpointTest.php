<?php

namespace Tests\Feature\Shared;

use Tests\TestCase;

/**
 * Phase 0 acceptance: GET /api/v1/health returns the standard ApiResponse
 * envelope — { success, data, meta, errors } — so every future v1 endpoint has
 * a proven contract to conform to.
 */
class HealthEndpointTest extends TestCase
{
    public function test_health_returns_the_standard_envelope(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data'    => ['status' => 'ok'],
                'errors'  => [],
            ])
            ->assertJsonStructure([
                'success',
                'data'   => ['status', 'service', 'db', 'env', 'time'],
                'meta',
                'errors',
            ]);
    }
}
