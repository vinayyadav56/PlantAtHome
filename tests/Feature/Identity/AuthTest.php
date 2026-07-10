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

    public function test_login_validation_error_uses_the_standard_envelope(): void
    {
        $res = $this->postJson('/api/v1/auth/login', ['email' => 'not-an-email']);

        $res->assertStatus(422)
            ->assertJson(['success' => false, 'data' => null])
            ->assertJsonStructure(['success', 'data', 'meta', 'errors' => [['code', 'field', 'message']]])
            ->assertJsonPath('errors.0.code', 'VALIDATION_ERROR');

        // password is required → a field-scoped error object is present.
        $fields = array_column($res->json('errors'), 'field');
        $this->assertContains('password', $fields);
    }

    public function test_validation_error_returns_json_without_an_accept_header(): void
    {
        // ForceJsonResponse must prevent the default 302-redirect for non-JSON
        // clients; the response is still the JSON envelope, never a redirect.
        $res = $this->post('/api/v1/auth/login', ['email' => 'x'], ['Accept' => 'text/html']);

        $res->assertStatus(422)->assertJsonPath('errors.0.code', 'VALIDATION_ERROR');
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

    public function test_refresh_rotates_the_token_pair(): void
    {
        $data = $this->loginData('owner.a@plantathome.test');
        $t1 = $data['tokens']['refresh_token'];

        // T1 -> T2
        $r2 = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $t1]);
        $r2->assertStatus(200)
            ->assertJsonStructure(['data' => ['tokens' => ['access_token', 'refresh_token']]]);
        $t2 = $r2->json('data.tokens.refresh_token');
        $this->assertNotSame($t1, $t2);

        // T2 (the current, un-reused token) still works and rotates to T3.
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $t2])
            ->assertStatus(200);
    }

    public function test_reusing_a_rotated_refresh_token_revokes_the_whole_family(): void
    {
        $data = $this->loginData('owner.b@plantathome.test');
        $t1 = $data['tokens']['refresh_token'];

        $t2 = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $t1])
            ->assertStatus(200)
            ->json('data.tokens.refresh_token');

        // Replaying the already-rotated T1 is detected as reuse (single-use).
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $t1])
            ->assertStatus(401)
            ->assertJsonPath('errors.0.code', 'REFRESH_REUSED');

        // Reuse detection revokes the ENTIRE family — the otherwise-valid
        // successor T2 is now dead too (stolen-lineage containment).
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $t2])
            ->assertStatus(401);
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
