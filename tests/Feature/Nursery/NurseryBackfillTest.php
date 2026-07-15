<?php

namespace Tests\Feature\Nursery;

use App\Shared\Infrastructure\Backfill\LegacyUuid;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2:backfill-nurseries against minimal in-sqlite replicas of the legacy
 * tables (only the columns the commands read). Proves the mapping, the
 * identity nursery-scope fix-up, deterministic uuids, and idempotent re-runs.
 */
class NurseryBackfillTest extends NurseryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Shared backfill kit state + the legacy_id column on identity_users.
        (require base_path('database/migrations/2026_07_29_000000_create_backfill_cursors_table.php'))->up();
        (require base_path('app/Modules/Identity/Database/Migrations/2026_07_29_000001_add_legacy_id_to_identity_users.php'))->up();

        $this->createLegacyTables();
        $this->seedLegacyRows();
    }

    public function test_backfill_is_mapped_and_idempotent(): void
    {
        $this->artisan('v2:backfill-users')->assertExitCode(0);
        $this->artisan('v2:backfill-nurseries')->assertExitCode(0);

        // ── Mapping ───────────────────────────────────────────────────────
        $this->assertSame(2, DB::table('nursery_nurseries')->count());

        $shopOne = DB::table('nursery_nurseries')->where('legacy_id', 11)->first();
        $this->assertSame(LegacyUuid::for('shops', 11), $shopOne->uuid);
        $this->assertSame(LegacyUuid::for('users', 101), $shopOne->owner_user_uuid);
        $this->assertSame('active', $shopOne->status);
        $this->assertSame('shop-one', $shopOne->slug);
        $this->assertSame(10.5, (float) $shopOne->commission_rate);
        $this->assertSame(['contact' => '123'], json_decode($shopOne->settings, true));

        $shopTwo = DB::table('nursery_nurseries')->where('legacy_id', 12)->first();
        $this->assertSame('pending', $shopTwo->status);
        $this->assertNull($shopTwo->commission_rate);

        $balance = DB::table('nursery_balances')->where('legacy_id', 21)->first();
        $this->assertSame($shopOne->id, $balance->nursery_id);
        $this->assertSame(250.0, (float) $balance->current_balance);

        $withdrawal = DB::table('nursery_withdrawals')->where('legacy_id', 31)->first();
        $this->assertSame(LegacyUuid::for('withdraws', 31), $withdrawal->uuid);
        $this->assertSame($shopOne->id, $withdrawal->nursery_id);
        $this->assertSame('approved', $withdrawal->status);
        $this->assertSame(40.5, (float) $withdrawal->amount);
        $this->assertSame('pending', DB::table('nursery_withdrawals')->where('legacy_id', 32)->value('status'));

        // ── Identity nursery-scope fix-up ─────────────────────────────────
        $this->assertSame(
            LegacyUuid::for('shops', 11),
            DB::table('identity_users')->where('legacy_id', 101)->value('nursery_id'),
        );
        $this->assertSame(
            LegacyUuid::for('shops', 12),
            DB::table('identity_users')->where('legacy_id', 102)->value('nursery_id'),
        );

        // ── Idempotent re-run: counts stable, uuids stable ────────────────
        $this->artisan('v2:backfill-users')->assertExitCode(0);
        $this->artisan('v2:backfill-nurseries')->assertExitCode(0);

        $this->assertSame(2, DB::table('nursery_nurseries')->count());
        $this->assertSame(2, DB::table('nursery_balances')->count());
        $this->assertSame(2, DB::table('nursery_withdrawals')->count());
        $this->assertSame($shopOne->uuid, DB::table('nursery_nurseries')->where('legacy_id', 11)->value('uuid'));
        $this->assertSame($shopOne->id, DB::table('nursery_nurseries')->where('legacy_id', 11)->value('id'));
    }

    /** Only the columns the backfill commands actually read. */
    private function createLegacyTables(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('email');
            $t->string('password');
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
            $t->string('name')->nullable();
            $t->string('slug')->nullable();
            $t->text('description')->nullable();
            $t->json('cover_image')->nullable();
            $t->json('logo')->nullable();
            $t->boolean('is_active')->default(false);
            $t->json('address')->nullable();
            $t->json('settings')->nullable();
            $t->timestamps();
        });

        Schema::create('balances', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('shop_id');
            $t->double('admin_commission_rate')->nullable();
            $t->double('total_earnings')->default(0);
            $t->double('withdrawn_amount')->default(0);
            $t->double('current_balance')->default(0);
            $t->json('payment_info')->nullable();
            $t->timestamps();
        });

        Schema::create('withdraws', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('shop_id');
            $t->float('amount');
            $t->string('payment_method')->nullable();
            $t->string('status')->default('pending');
            $t->text('details')->nullable();
            $t->text('note')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });
    }

    private function seedLegacyRows(): void
    {
        $now = now();

        DB::table('users')->insert([
            ['id' => 101, 'name' => 'Legacy Owner One', 'email' => 'legacy.owner1@example.test', 'password' => 'bcrypt-hash-1', 'shop_id' => 11, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 102, 'name' => 'Legacy Owner Two', 'email' => 'legacy.owner2@example.test', 'password' => 'bcrypt-hash-2', 'shop_id' => 12, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('permissions')->insert([['id' => 1, 'name' => 'store_owner']]);
        DB::table('model_has_permissions')->insert([
            ['permission_id' => 1, 'model_type' => 'Marvel\\Database\\Models\\User', 'model_id' => 101],
            ['permission_id' => 1, 'model_type' => 'Marvel\\Database\\Models\\User', 'model_id' => 102],
        ]);

        DB::table('shops')->insert([
            ['id' => 11, 'owner_id' => 101, 'name' => 'Shop One', 'slug' => 'shop-one', 'description' => 'First shop', 'is_active' => 1, 'settings' => json_encode(['contact' => '123']), 'created_at' => $now, 'updated_at' => $now],
            ['id' => 12, 'owner_id' => 102, 'name' => 'Shop Two', 'slug' => 'shop-two', 'description' => null, 'is_active' => 0, 'settings' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('balances')->insert([
            ['id' => 21, 'shop_id' => 11, 'admin_commission_rate' => 10.5, 'total_earnings' => 1000, 'withdrawn_amount' => 100, 'current_balance' => 250, 'payment_info' => json_encode(['bank' => 'HDFC']), 'created_at' => $now, 'updated_at' => $now],
            ['id' => 22, 'shop_id' => 12, 'admin_commission_rate' => null, 'total_earnings' => 0, 'withdrawn_amount' => 0, 'current_balance' => 0, 'payment_info' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('withdraws')->insert([
            ['id' => 31, 'shop_id' => 11, 'amount' => 40.5, 'payment_method' => 'bank', 'status' => 'approved', 'details' => 'ref-1', 'note' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 32, 'shop_id' => 12, 'amount' => 10, 'payment_method' => null, 'status' => 'pending', 'details' => null, 'note' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
