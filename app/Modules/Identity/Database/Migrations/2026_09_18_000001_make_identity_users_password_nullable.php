<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * identity_users.password must allow NULL.
 *
 * Legacy `users.password` has been nullable since marvel 2021_04_17_051901 — an
 * account created through social login or phone OTP genuinely has no password.
 * The legacy->v2 mirror (v2:backfill-users) copies that column verbatim, but
 * identity_users declared it NOT NULL, so the first passwordless account to
 * reach the mirror threw:
 *
 *   SQLSTATE[23000]: Integrity constraint violation: 1048
 *   Column 'password' cannot be null
 *
 * That one row did not just skip itself. BackfillCommand upserts a whole
 * 200-row chunk in a single statement and only advances `backfill_cursors`
 * AFTER the chunk loop finishes, so the throw aborted the run and left the
 * cursor untouched — the same rows were re-read and re-thrown every five
 * minutes, and the user mirror stopped dead. Any account created in the legacy
 * admin panel from that moment on could not reach the V2 API, which is the
 * same 401 class of failure the mirror was built to prevent.
 *
 * NULL is the honest value: "this account has no password and authenticates
 * another way". It cannot become a login bypass —
 * AuthService::login falls back to a dummy hash that can never match
 * (`$user?->password ?? '$2y$10$usesomesilly…'`), and mirrorLegacyUser already
 * returns early for any legacy row with an empty password.
 *
 * Driver-aware on purpose: Laravel 10 needs doctrine/dbal for ->change(), and
 * on sqlite dbal recreates the table, which would drop the role_id foreign key.
 * Fresh databases get a nullable column straight from the create migration, so
 * this only has real work to do on an already-deployed MySQL database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('identity_users') || ! Schema::hasColumn('identity_users', 'password')) {
            return;
        }

        if ($this->isNullable()) {
            return;
        }

        DB::statement('ALTER TABLE `identity_users` MODIFY `password` VARCHAR(255) NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('identity_users') || ! Schema::hasColumn('identity_users', 'password')) {
            return;
        }

        if (! $this->isNullable()) {
            return;
        }

        // Narrowing would throw on exactly the rows this migration exists to
        // allow, so give them an empty hash first. Hash::check against '' is
        // false, so those accounts stay unauthenticatable either way.
        DB::table('identity_users')->whereNull('password')->update(['password' => '']);

        DB::statement('ALTER TABLE `identity_users` MODIFY `password` VARCHAR(255) NOT NULL');
    }

    /** True when identity_users.password already accepts NULL. */
    private function isNullable(): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            foreach (DB::select('PRAGMA table_info(identity_users)') as $col) {
                if (($col->name ?? null) === 'password') {
                    return (int) ($col->notnull ?? 0) === 0;
                }
            }

            return true;
        }

        if ($driver === 'mysql') {
            $row = DB::selectOne(
                'SELECT IS_NULLABLE FROM information_schema.columns
                  WHERE table_schema = DATABASE()
                    AND table_name = ?
                    AND column_name = ?',
                ['identity_users', 'password']
            );

            return $row === null || strtoupper((string) $row->IS_NULLABLE) === 'YES';
        }

        // Unknown driver: treat as already-nullable rather than issue MySQL DDL
        // it may not understand.
        return true;
    }
};
