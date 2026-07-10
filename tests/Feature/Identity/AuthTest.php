<?php

namespace Tests\Feature\Identity;

/**
 * Phase 1 acceptance (part 1): login issues a JWT; the token authenticates;
 * refresh rotates and single-uses the refresh token; logout revokes.
 */
class AuthTest extends IdentityTestCase
{
    public function test_login_issues_a_jwt_and_refresh_token(): void
    {
        $res = $this->postJson('/api/v1/auth/login', [
            'email'    => 'superadmin@plantathome.test',
            'password' => $this->demoPassword,
        ]);

        $res->assertStatus(200)
            ->assertJson(['success' => true, 'errors' => []])
            ->assertJsonStructure([
                'data' => [
                    'tokens' => ['access_token', 'refresh_token', 'token_type', 'expires_in'],
                    'user'   => ['uuid', 'email', 'role', 'permissions'],
                ],
            ]);

        $this->assertSame('Bearer', $res->json('data.tokens.token_type'));
        $this->assertSame('super_admin', $res->json('data.user.role'));
        // JWT has three dot-separated segments.
        $this->assertCount(3, explode('.', $res->json('data.tokens.access_token')));
    }

    public function test_login_rejects_a_wrong_password(): void
    {
        $res = $this->postJson('/api/v1/auth/login', [
            'email'    => 'superadmin@plantathome.test',
            'password' => 'wrong-password',
        ]);

        $res->assertStatus(401)
            ->assertJson(['success' => false])
            ->assertJsonPath('errors.0.code', 'INVALID_CREDENTIALS');
    }

    public function test_login_rejects_an_unknown_email(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email'    => 'nobody@plantathome.test',
            'password' => $this->demoPassword,
        ])->assertStatus(401)->assertJsonPath('errors.0.code', 'INVALID_CREDENTIALS');
    }

    public function test_login_validates_input(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'not-an-email'])
            ->assertStatus(422);
    }

    public function test_me_requires_a_token(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('errors.0.code', 'UNAUTHENTICATED');
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $token = $this->accessToken('customer@plantathome.test');

        $this->getJson('/api/v1/auth/me', $this->bearer($token))
            ->assertStatus(200)
            ->assertJsonPath('data.user.email', 'customer@plantathome.test')
            ->assertJsonPath('data.user.role', 'customer');
    }

    public function test_a_garbage_token_is_rejected(): void
    {
        $this->getJson('/api/v1/auth/me', $this->bearer('not.a.jwt'))
            ->assertStatus(401)
            ->assertJsonPath('errors.0.code', 'TOKEN_INVALID');
    }

    public function test_refresh_rotates_tokens_and_single_uses_the_old_one(): void
    {
        $data = $this->loginData('owner.a@plantathome.test');
        $oldRefresh = $data['tokens']['refresh_token'];

        // First refresh succeeds and returns a new pair.
        $refreshed = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $oldRefresh]);
        $refreshed->assertStatus(200)
            ->assertJsonStructure(['data' => ['tokens' => ['access_token', 'refresh_token']]]);

        $newRefresh = $refreshed->json('data.tokens.refresh_token');
        $this->assertNotSame($oldRefresh, $newRefresh);

        // Re-using the OLD refresh token is rejected (rotation = single use).
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $oldRefresh])
            ->assertStatus(401)
            ->assertJsonPath('errors.0.code', 'REFRESH_INVALID');

        // The NEW refresh token still works.
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $newRefresh])
            ->assertStatus(200);
    }

    public function test_logout_revokes_refresh_tokens(): void
    {
        $data = $this->loginData('owner.b@plantathome.test');
        $access = $data['tokens']['access_token'];
        $refresh = $data['tokens']['refresh_token'];

        $this->postJson('/api/v1/auth/logout', [], $this->bearer($access))
            ->assertStatus(200)
            ->assertJsonPath('data.logged_out', true);

        // After logout, the refresh token no longer works.
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])
            ->assertStatus(401);
    }
}
