<?php

namespace Tests\Feature\Shared;

use Tests\TestCase;

/**
 * The envelope contract must hold for the error paths too — a v1 route that
 * doesn't exist, or the wrong HTTP method, still returns
 * { success:false, data:null, meta, errors:[{code,message}] } via the
 * ApiExceptionRenderer, not Laravel's default error body.
 */
class ApiEnvelopeTest extends TestCase
{
    public function test_unknown_v1_route_returns_the_envelope_404(): void
    {
        $this->getJson('/api/v1/does-not-exist')
            ->assertStatus(404)
            ->assertJson(['success' => false, 'data' => null])
            ->assertJsonStructure(['success', 'data', 'meta', 'errors' => [['code', 'message']]])
            ->assertJsonPath('errors.0.code', 'NOT_FOUND');
    }

    public function test_wrong_method_on_a_v1_route_returns_the_envelope_405(): void
    {
        // /api/v1/health is GET-only.
        $this->postJson('/api/v1/health')
            ->assertStatus(405)
            ->assertJson(['success' => false])
            ->assertJsonPath('errors.0.code', 'METHOD_NOT_ALLOWED');
    }
}
