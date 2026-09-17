<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Shared\Infrastructure\Backfill\LegacyUuid;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Regression: one passwordless legacy account killed the whole legacy->v2 user
 * mirror in production.
 *
 * `users.password` has been nullable since marvel 2021_04_17_051901 — social
 * login and phone OTP accounts have no password — but identity_users declared
 * it NOT NULL. v2:backfill-users copies the column verbatim, so the first such
 * signup produced:
 *
 *   SQLSTATE[23000]: Integrity constraint violation: 1048
 *   Column 'password' cannot be null
 *
 * And it did not merely skip that row. BackfillCommand upserts a 200-row chunk
 * in one statement and only advances `backfill_cursors` after the chunk loop,
 * so the throw aborted the run with the cursor untouched: the same rows were
 * re-read and re-thrown every five minutes, and no account created in the
 * legacy admin panel afterwards could reach the V2 API.
 *
 * The passwordless user must therefore be mirrored WITH a null password (it is
 * a real account) while staying unauthenticatable, and — the part that actually
 * mattered — the users either side of it in the same chunk must still land.
 */
final class UserMirrorNullPasswordTest extends IdentityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require base_path('database/migrations/2026_07_29_000000_create_backfill_cursors_table.php'))->up();
        (require base_path('app/Modules/Identity/Database/Migrations/2026_07_29_000001_add_legacy_id_to_identity_users.php'))->up();

        // Legacy replicas — only the columns v2:backfill-users reads. `password`
        // is nullable here because it is nullable in production; declaring it
        // NOT NULL in a test replica is precisely what hid this bug.
        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('email');
            $t->string('password')->nullable();
            $t->unsignedBigInteger('shop_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('email_verified_at')->nullable();
            $t->timestamps();
        });

        Schema::create('permissions', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
        });

        Schema::create('model_has_permissions', function (Blueprint $t) {
            $t->unsignedBigInteger('permission_id');
            $t->string('model_type');
            $t->unsignedBigInteger('model_id');
        });

        Schema::create('shops', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('owner_id');
            $t->timestamps();
        });

        $now = now();
        DB::table('users')->insert([
            // Ordinary password account, mirrored before the bad row.
            ['id' => 14, 'name' => 'Before Row', 'email' => 'before@example.test', 'password' => Hash::make('secret-before'), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            // The production shape: a Google/OTP signup with no password at all.
            ['id' => 15, 'name' => 'Priyanka Kumari', 'email' => 'passwordless@example.test', 'password' => null, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            // Mirrored after the bad row, in the SAME chunk — this is the one
            // that silently never arrived while the mirror was wedged.
            ['id' => 16, 'name' => 'After Row', 'email' => 'after@example.test', 'password' => Hash::make('secret-after'), 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function test_a_passwordless_legacy_account_does_not_break_the_mirror(): void
    {
        $this->artisan('v2:backfill-users')->assertExitCode(0);

        $mirrored = DB::table('identity_users')->whereNotNull('legacy_id')->pluck('email', 'legacy_id')->all();

        $this->assertSame(
            [14 => 'before@example.test', 15 => 'passwordless@example.test', 16 => 'after@example.test'],
            $mirrored,
            'every legacy user in the chunk must be mirrored, including the ones after the passwordless row',
        );

        $row = DB::table('identity_users')->where('legacy_id', 15)->first();
        $this->assertNull($row->password, 'a passwordless account is mirrored with a null password, not a fabricated hash');
        $this->assertSame(LegacyUuid::for('users', 15), $row->uuid);
    }

    public function test_the_cursor_advances_so_the_mirror_does_not_re_read_forever(): void
    {
        $this->artisan('v2:backfill-users')->assertExitCode(0);

        $cursor = DB::table('backfill_cursors')->where('resource', 'users')->first();
        $this->assertNotNull($cursor, 'the run must record a cursor');
        $this->assertNotNull(
            $cursor->last_source_updated_at,
            'a wedged run leaves this null and re-reads the same failing rows every five minutes',
        );
    }

    public function test_a_null_password_can_never_authenticate(): void
    {
        $this->artisan('v2:backfill-users')->assertExitCode(0);

        // An empty password never reaches the credential check — validation
        // rejects it first. Asserted separately so this test cannot pass just
        // because the request was malformed.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'passwordless@example.test',
            'password' => '',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'VALIDATION_ERROR');

        // Anything that does reach the credential check must fail, including
        // another user's real password and the literal strings a null column
        // could plausibly be coerced into.
        foreach (['secret-before', 'null', 'password', 'NULL'] as $attempt) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'passwordless@example.test',
                'password' => $attempt,
            ])
                ->assertStatus(401)
                ->assertJsonPath('errors.0.code', 'INVALID_CREDENTIALS');
        }
    }
}
