<?php

namespace Tests\Feature\Nursery;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lossless admin vendor-create: the full legacy payload (owner credentials,
 * bank details, contact/geo/categories/service areas) lands on the nursery,
 * the identity login, AND the legacy shops projection — in one request.
 * Legacy tables are minimal in-sqlite replicas incl. the vendor-profile
 * columns, so the service's hasColumn drift guards take the write path.
 */
class NurseryVendorCreateTest extends NurseryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createLegacyTables();

        // Deterministic mail config: array transport + a from address, so the
        // credentials email takes the real send path (and succeeds).
        config(['mail.default' => 'array', 'mail.from.address' => 'noreply@plantathome.test']);

        DB::table('permissions')->insert([
            ['id' => 1, 'name' => 'store_owner'],
            ['id' => 2, 'name' => 'customer'],
        ]);
    }

    public function test_full_payload_create_is_lossless(): void
    {
        $admin = $this->bearer($this->accessToken('admin@plantathome.test'));

        $response = $this->postJson('/api/v1/nurseries', [
            'name'           => 'Test Nursery',
            'description'    => 'Full vendor payload',
            'contact_person' => 'Ravi Kumar',
            'mobile'         => '9876543210',
            'upi'            => 'ravi@upi',
            'lat'            => 28.6139,
            'lng'            => 77.209,
            'address'        => ['city' => 'Delhi', 'zip' => '110001'],
            'settings'       => ['compliance' => ['gst' => '07abcde1234f1z5']],
            'categories'     => [1, 2],
            'service_areas'  => [
                ['city' => 'Delhi', 'pincode' => '110001', 'fulfillment_mode' => 'local', 'eta_days' => 2],
            ],
            'balance'        => ['payment_info' => ['account' => '12345678', 'bank' => 'HDFC']],
            'owner_email'    => 'vendor.owner@example.test',
            'owner_name'     => 'Vendor Owner',
            'owner_password' => 'Secret#123',
        ], $admin);

        // ── Response: auto-slug + vendor fields + owner + email flag ──────
        $response->assertStatus(201)
            ->assertJsonPath('data.slug', 'test-nursery')
            ->assertJsonPath('data.contact_person', 'Ravi Kumar')
            ->assertJsonPath('data.mobile', '9876543210')
            ->assertJsonPath('data.upi', 'ravi@upi')
            ->assertJsonPath('data.gst_number', '07ABCDE1234F1Z5')
            ->assertJsonPath('data.categories', [1, 2])
            ->assertJsonPath('data.balance.payment_info.bank', 'HDFC')
            ->assertJsonPath('data.owner.email', 'vendor.owner@example.test');
        // Array mailer in tests: the send path runs for real and succeeds.
        $this->assertTrue($response->json('data.credentials_email_sent'));

        $uuid = $response->json('data.uuid');

        // ── Nursery row persisted the vendor columns ──────────────────────
        $nursery = DB::table('nursery_nurseries')->where('uuid', $uuid)->first();
        $this->assertSame('test-nursery', $nursery->slug);
        $this->assertSame('Ravi Kumar', $nursery->contact_person);
        $this->assertSame(28.6139, (float) $nursery->lat);
        $this->assertSame(77.209, (float) $nursery->lng);
        $this->assertSame([1, 2], json_decode($nursery->category_ids, true));
        $this->assertSame('Delhi', json_decode($nursery->service_areas, true)[0]['city']);
        $this->assertSame(
            ['account' => '12345678', 'bank' => 'HDFC'],
            json_decode(DB::table('nursery_balances')->where('nursery_id', $nursery->id)->value('payment_info'), true),
        );

        // ── Identity owner: created, scoped, and can actually log in ──────
        $identityOwner = DB::table('identity_users')->where('email', 'vendor.owner@example.test')->first();
        $this->assertNotNull($identityOwner);
        $this->assertSame($uuid, $identityOwner->nursery_id);
        $this->assertSame($identityOwner->uuid, $nursery->owner_user_uuid);
        $this->assertSame(
            'nursery_owner',
            DB::table('identity_roles')->where('id', $identityOwner->role_id)->value('name'),
        );
        $this->postJson('/api/v1/auth/login', [
            'email'    => 'vendor.owner@example.test',
            'password' => 'Secret#123',
        ])->assertStatus(200);

        // ── Legacy projection: shops + users + grants + balances + pivots ─
        $shop = DB::table('shops')->where('slug', 'test-nursery')->first();
        $this->assertNotNull($shop);
        $this->assertSame((int) $shop->id, (int) $nursery->legacy_id);
        $this->assertSame(0, (int) $shop->is_active);
        $this->assertSame('Ravi Kumar', $shop->contact_person);
        $this->assertSame('9876543210', $shop->mobile);
        $this->assertSame('ravi@upi', $shop->upi);
        $this->assertSame('07ABCDE1234F1Z5', $shop->gst_number);
        $this->assertSame('Delhi', json_decode($shop->address, true)['city']);

        $legacyUser = DB::table('users')->where('email', 'vendor.owner@example.test')->first();
        $this->assertNotNull($legacyUser);
        $this->assertSame((int) $legacyUser->id, (int) $shop->owner_id);
        $this->assertSame($identityOwner->password, $legacyUser->password); // same bcrypt hash
        $this->assertSame(2, DB::table('model_has_permissions')->where('model_id', $legacyUser->id)->count());
        $this->assertTrue(
            DB::table('model_has_permissions')
                ->where('model_id', $legacyUser->id)->where('permission_id', 1) // store_owner
                ->exists(),
        );

        $this->assertSame(
            ['account' => '12345678', 'bank' => 'HDFC'],
            json_decode(DB::table('balances')->where('shop_id', $shop->id)->value('payment_info'), true),
        );
        $this->assertSame([1, 2], DB::table('category_shop')->where('shop_id', $shop->id)->orderBy('category_id')->pluck('category_id')->map(fn ($id) => (int) $id)->all());

        $area = DB::table('vendor_service_areas')->where('shop_id', $shop->id)->first();
        $this->assertSame('Delhi', $area->city);
        $this->assertSame('110001', $area->pincode);
        $this->assertSame('local', $area->fulfillment_mode);
        $this->assertSame(2, (int) $area->eta_days);
    }

    public function test_duplicate_name_gets_suffixed_slug(): void
    {
        $admin = $this->bearer($this->accessToken('admin@plantathome.test'));

        $first = $this->postJson('/api/v1/nurseries', ['name' => 'Green Valley'], $admin);
        $second = $this->postJson('/api/v1/nurseries', ['name' => 'Green Valley'], $admin);

        $first->assertStatus(201)->assertJsonPath('data.slug', 'green-valley');
        $second->assertStatus(201)->assertJsonPath('data.slug', 'green-valley-2');
        $this->assertSame(2, DB::table('nursery_nurseries')->where('name', 'Green Valley')->count());
    }

    public function test_existing_identity_owner_is_reused(): void
    {
        $admin = $this->bearer($this->accessToken('admin@plantathome.test'));

        // Demo owner.b already exists in identity — reuse, never duplicate.
        $before = DB::table('identity_users')->where('email', 'owner.b@plantathome.test')->first();

        $response = $this->postJson('/api/v1/nurseries', [
            'name'           => 'Reuse Nursery',
            'owner_email'    => 'owner.b@plantathome.test',
            'owner_password' => 'IgnoredForExisting#1',
        ], $admin);
        $response->assertStatus(201)
            ->assertJsonPath('data.owner.email', 'owner.b@plantathome.test');

        $this->assertSame(1, DB::table('identity_users')->where('email', 'owner.b@plantathome.test')->count());
        $after = DB::table('identity_users')->where('email', 'owner.b@plantathome.test')->first();
        $this->assertSame($before->uuid, $after->uuid);
        $this->assertSame($before->password, $after->password); // existing password untouched
        $this->assertSame($response->json('data.uuid'), $after->nursery_id);
        $this->assertSame($before->uuid, $response->json('data.owner.uuid'));
    }

    /** Legacy replicas incl. the vendor-profile shop columns the projection hasColumn-checks. */
    private function createLegacyTables(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('email');
            $t->string('password');
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
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->string('name')->nullable();
            $t->string('slug')->nullable();
            $t->text('description')->nullable();
            $t->json('cover_image')->nullable();
            $t->json('logo')->nullable();
            $t->boolean('is_active')->default(false);
            $t->json('address')->nullable();
            $t->json('settings')->nullable();
            $t->string('contact_person')->nullable();
            $t->string('mobile')->nullable();
            $t->string('upi')->nullable();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->string('gst_number')->nullable();
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

        Schema::create('category_shop', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('shop_id');
            $t->unsignedBigInteger('category_id');
        });

        Schema::create('vendor_service_areas', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('shop_id');
            $t->string('city');
            $t->string('pincode', 12)->nullable();
            $t->string('fulfillment_mode')->default('local');
            $t->unsignedSmallInteger('eta_days')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }
}
