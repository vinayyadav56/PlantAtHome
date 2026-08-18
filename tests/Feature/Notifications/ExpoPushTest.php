<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\DeviceToken;
use App\Services\ExpoPushService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ExpoPushService: posts to Expo for a user's registered tokens, no-ops without tokens,
 * and prunes tokens Expo reports as DeviceNotRegistered. Expo is HTTP-faked (no secret,
 * no real send).
 */
final class ExpoPushTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('sqlite');

        Schema::create('device_tokens', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id')->index();
            $t->string('token')->unique();
            $t->string('platform')->nullable();
            $t->timestamps();
        });
    }

    private function service(): ExpoPushService
    {
        return new ExpoPushService();
    }

    public function test_sends_to_all_of_a_users_tokens(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok'], ['status' => 'ok']]])]);
        DeviceToken::create(['user_id' => 1, 'token' => 'ExponentPushToken[a]', 'platform' => 'ios']);
        DeviceToken::create(['user_id' => 1, 'token' => 'ExponentPushToken[b]', 'platform' => 'android']);
        DeviceToken::create(['user_id' => 2, 'token' => 'ExponentPushToken[other]']);

        $this->service()->sendToUser((object) ['id' => 1], 'Title', 'Body', ['type' => 'order']);

        Http::assertSent(function ($request) {
            $tos = collect($request->data())->pluck('to')->all();
            return str_contains($request->url(), 'exp.host')
                && count($tos) === 2
                && in_array('ExponentPushToken[a]', $tos, true)
                && in_array('ExponentPushToken[b]', $tos, true)
                && !in_array('ExponentPushToken[other]', $tos, true);
        });
    }

    public function test_noop_when_user_has_no_tokens(): void
    {
        Http::fake();
        $this->service()->sendToUser((object) ['id' => 99], 'Title', 'Body');
        Http::assertNothingSent();
    }

    public function test_prunes_device_not_registered_tokens(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [
            ['status' => 'ok'],
            ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']],
        ]])]);
        DeviceToken::create(['user_id' => 1, 'token' => 'ExponentPushToken[live]']);
        DeviceToken::create(['user_id' => 1, 'token' => 'ExponentPushToken[dead]']);

        $this->service()->sendToUser((object) ['id' => 1], 'T', 'B');

        $this->assertNotNull(DeviceToken::where('token', 'ExponentPushToken[live]')->first());
        $this->assertNull(DeviceToken::where('token', 'ExponentPushToken[dead]')->first(), 'dead token should be pruned');
    }

    public function test_registering_the_same_token_repoints_it_to_the_new_user(): void
    {
        DeviceToken::updateOrCreate(['token' => 'ExponentPushToken[shared]'], ['user_id' => 1, 'platform' => 'ios']);
        DeviceToken::updateOrCreate(['token' => 'ExponentPushToken[shared]'], ['user_id' => 2, 'platform' => 'ios']);

        $this->assertSame(1, DeviceToken::where('token', 'ExponentPushToken[shared]')->count());
        $this->assertSame(2, (int) DeviceToken::where('token', 'ExponentPushToken[shared]')->first()->user_id);
    }
}
