<?php

namespace Tests\Feature\Location;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\User;
use Marvel\Http\Controllers\UserController;
use Tests\TestCase;

/**
 * PUT me/shopping-city saves any city the storefront picker offers. Since 2026-10-12 that is
 * every listed city, not only the nursery ones: a refused pick left users.preferred_city on the
 * previous city, which a new device then adopted and the gifting checkout gated on.
 */
final class ShoppingCityProfileTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        DB::purge('sqlite');

        // AvailabilityService's constructor chain reads settings.
        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->json('options')->nullable();
            $t->string('language', 8)->default('en');
            $t->timestamps();
        });
        DB::table('settings')->insert(['options' => json_encode([]), 'language' => 'en', 'created_at' => now(), 'updated_at' => now()]);

        Schema::create('cities', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name');
            $t->string('status')->default('active');
            $t->boolean('is_serviceable')->default(false);
            $t->boolean('is_subdivision')->default(false);
        });
        Schema::create('users', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('preferred_city')->nullable();
            $t->timestamps();
        });

        DB::table('cities')->insert([
            ['id' => 1, 'name' => 'Gurugram', 'status' => 'active', 'is_serviceable' => 1],
            ['id' => 2, 'name' => 'Pune', 'status' => 'active', 'is_serviceable' => 0],
            ['id' => 3, 'name' => 'Kota', 'status' => 'paused', 'is_serviceable' => 1],
            ['id' => 4, 'name' => 'Ooty', 'status' => 'disabled', 'is_serviceable' => 0],
        ]);
        DB::table('cities')->insert(['id' => 5, 'name' => 'Mumbai Suburban', 'status' => 'active', 'is_serviceable' => 0, 'is_subdivision' => 1]);
    }

    private function pick(User $user, string $city)
    {
        $request = Request::create('/api/me/shopping-city', 'PUT', ['city' => $city]);
        $request->setUserResolver(fn () => $user);

        return app(UserController::class)->updateShoppingCity($request);
    }

    public function test_a_city_without_a_nursery_is_saved(): void
    {
        $user = User::forceCreate(['name' => 'Asha', 'preferred_city' => 'Gurugram']);

        $this->assertEquals(['preferred_city' => 'Pune', 'city_id' => 2], $this->pick($user, 'pune'));
        $this->assertSame('Pune', $user->fresh()->preferred_city);
    }

    public function test_an_old_name_is_saved_as_the_city(): void
    {
        $user = User::forceCreate(['name' => 'Asha']);

        $this->assertSame('Gurugram', $this->pick($user, 'Gurgaon')['preferred_city']);
    }

    public function test_a_switched_off_unknown_or_subdivision_city_is_refused_and_nothing_changes(): void
    {
        $user = User::forceCreate(['name' => 'Asha', 'preferred_city' => 'Gurugram']);

        foreach (['Kota', 'Ooty', 'Atlantis', 'Mumbai Suburban'] as $city) {
            $res = $this->pick($user, $city);
            $this->assertSame(422, $res->getStatusCode(), $city);
            $this->assertSame('CITY_NOT_SERVICEABLE', $res->getData(true)['code'], $city);
        }
        $this->assertSame('Gurugram', $user->fresh()->preferred_city);
    }
}
