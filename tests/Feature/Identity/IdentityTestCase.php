<?php

namespace Tests\Feature\Identity;

use App\Modules\Identity\Database\Seeders\IdentityAccessSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Base for Identity feature tests. Runs on an isolated in-memory sqlite DB with
 * only the outbox + identity tables created and the RBAC baseline (+ demo users,
 * since APP_ENV=testing is non-production) seeded. Never touches the real DB.
 */
abstract class IdentityTestCase extends TestCase
{
    protected string $demoPassword = 'Passw0rd!';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver'   => 'sqlite',
                'database' => ':memory:',
                'prefix'   => '',
            ],
            // Deterministic signing secret for the test process.
            'identity.jwt.secret' => 'test-identity-secret-key-0123456789',
        ]);
        DB::purge('sqlite');

        $this->createSchema();
        (new IdentityAccessSeeder())->run();
    }

    private function createSchema(): void
    {
        // Sanctum's personal_access_tokens — exists in the real DB. The `api`
        // group's throttle limiter calls $request->user(), which invokes the
        // Sanctum guard when a Bearer token is present; it queries this table
        // (our JWT never matches → null → throttle keys by IP). Present here so
        // the isolated sqlite DB behaves like production.
        Schema::create('personal_access_tokens', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('tokenable_type');
            $t->unsignedBigInteger('tokenable_id');
            $t->string('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });

        // Outbox — login publishes UserLoggedIn through it.
        Schema::create('outbox', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('event_id')->unique();
            $t->string('event_name');
            $t->unsignedSmallInteger('version')->default(1);
            $t->json('payload')->nullable();
            $t->string('status')->default('pending');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->string('occurred_at');
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });

        // Idempotency ledger for outbox delivery.
        Schema::create('processed_events', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('event_id');
            $t->string('subscriber', 191);
            $t->timestamp('processed_at')->nullable();
            $t->unique(['event_id', 'subscriber']);
        });

        $dir = base_path('app/Modules/Identity/Database/Migrations');
        foreach ([
            '2026_07_16_000000_create_identity_roles_permissions_tables.php',
            '2026_07_16_000001_create_identity_users_table.php',
            '2026_07_16_000002_create_identity_refresh_tokens_table.php',
        ] as $file) {
            (require $dir.'/'.$file)->up();
        }
    }

    /** Log in a demo user and return the full envelope `data` block. */
    protected function loginData(string $email): array
    {
        $res = $this->postJson('/api/v1/auth/login', [
            'email'    => $email,
            'password' => $this->demoPassword,
        ]);
        $res->assertStatus(200);

        return $res->json('data');
    }

    /** Log in and return just the access token. */
    protected function accessToken(string $email): string
    {
        return $this->loginData($email)['tokens']['access_token'];
    }

    /** Authorization header array for a bearer token. */
    protected function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }
}
