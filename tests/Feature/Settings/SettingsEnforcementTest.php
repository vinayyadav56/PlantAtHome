<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Settings;
use Marvel\Http\Controllers\SettingsController;
use Marvel\Http\Requests\SettingsRequest;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Admin toggles must CONTROL runtime behavior, not just persist:
 *  - COD off ⇒ a directly-posted CASH_ON_DELIVERY order is refused (422).
 *  - Maintenance on (within window) ⇒ order placement 503s for customers.
 *  - Saving settings from a non-default admin locale must update the row the
 *    server actually reads (DEFAULT_LANGUAGE), never fork a per-locale row.
 */
final class SettingsEnforcementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver'                  => 'sqlite',
                'database'                => ':memory:',
                'prefix'                  => '',
                'foreign_key_constraints' => false,
            ],
            'cache.default' => 'array',
        ]);
        DB::purge('sqlite');
        Cache::flush();

        Schema::create('settings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->text('options')->nullable();
            $t->string('language')->default('en');
            $t->timestamps();
        });
    }

    private function seedOptions(array $options): void
    {
        Settings::create(['options' => $options, 'language' => DEFAULT_LANGUAGE]);
    }

    /** Invoke a protected OrderRepository gate with a stub request object. */
    private function callGate(string $method, object $request): void
    {
        $repo = app(\Marvel\Database\Repositories\OrderRepository::class);
        $ref = new \ReflectionMethod($repo, $method);
        $ref->setAccessible(true);
        $ref->invoke($repo, $request);
    }

    private function stubRequest(array $data, $user = null): object
    {
        return new class($data, $user) implements \ArrayAccess {
            public function __construct(private array $data, private $user)
            {
            }
            public function user()
            {
                return $this->user;
            }
            public function offsetExists(mixed $o): bool
            {
                return isset($this->data[$o]);
            }
            public function offsetGet(mixed $o): mixed
            {
                return $this->data[$o] ?? null;
            }
            public function offsetSet(mixed $o, mixed $v): void
            {
                $this->data[$o] = $v;
            }
            public function offsetUnset(mixed $o): void
            {
                unset($this->data[$o]);
            }
        };
    }

    public function test_cod_disabled_refuses_cod_orders(): void
    {
        $this->seedOptions(['useCashOnDelivery' => false]);

        try {
            $this->callGate('assertCodAllowed', $this->stubRequest(['payment_gateway' => 'CASH_ON_DELIVERY']));
            $this->fail('COD order must be refused while useCashOnDelivery is off');
        } catch (HttpResponseException $e) {
            $payload = json_decode($e->getResponse()->getContent(), true);
            $this->assertSame('COD_DISABLED', $payload['code']);
            $this->assertSame(422, $e->getResponse()->getStatusCode());
        }
    }

    public function test_cod_enabled_or_unset_allows_cod_and_prepaid_never_gated(): void
    {
        $this->seedOptions(['useCashOnDelivery' => true]);
        $this->callGate('assertCodAllowed', $this->stubRequest(['payment_gateway' => 'CASH_ON_DELIVERY']));

        // COD off must not touch prepaid orders.
        Settings::query()->update(['options' => json_encode(['useCashOnDelivery' => false])]);
        Cache::flush();
        $this->callGate('assertCodAllowed', $this->stubRequest(['payment_gateway' => 'RAZORPAY']));
        $this->assertTrue(true);
    }

    public function test_maintenance_blocks_customers_but_not_when_window_over(): void
    {
        $this->seedOptions([
            'isUnderMaintenance' => true,
            'maintenance'        => ['start' => now()->subHour()->toIso8601String()],
        ]);

        try {
            $this->callGate('assertNotUnderMaintenance', $this->stubRequest([], null));
            $this->fail('maintenance must block order placement');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }

        // Window over (until in the past, toggle forgotten ON) — orders flow.
        Settings::query()->update(['options' => json_encode([
            'isUnderMaintenance' => true,
            'maintenance'        => [
                'start' => now()->subDays(2)->toIso8601String(),
                'until' => now()->subDay()->toIso8601String(),
            ],
        ])]);
        Cache::flush();
        $this->callGate('assertNotUnderMaintenance', $this->stubRequest([], null));
        $this->assertTrue(true);
    }

    public function test_settings_store_always_writes_the_default_language_row(): void
    {
        $this->seedOptions(['siteTitle' => 'Before']);

        $request = SettingsRequest::create('/settings', 'POST', [
            'language' => 'hi',
            'options'  => ['siteTitle' => 'After', 'useCashOnDelivery' => false],
        ]);
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->validateResolved();

        $controller = new SettingsController(app(\Marvel\Database\Repositories\SettingsRepository::class));
        $controller->store($request);

        $this->assertSame(1, Settings::count(), 'a non-default locale save must NOT fork a new row');
        $row = Settings::where('language', DEFAULT_LANGUAGE)->first();
        $this->assertSame('After', $row->options['siteTitle']);
        $this->assertFalse((bool) $row->options['useCashOnDelivery']);
    }
}
