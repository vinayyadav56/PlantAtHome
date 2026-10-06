<?php

namespace Tests\Feature\Tracking;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Tracking harness (AccountingTestCase pattern): sqlite :memory: running the REAL
 * analytics migrations, so the columns and indexes under test are the deployed ones.
 * `users` and `orders` are stubbed to the handful of columns the reports read.
 */
abstract class TrackingTestCase extends TestCase
{
    public const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';
    public const SAFARI_IOS = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
    public const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
    public const GPTBOT = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)';
    public const CURL = 'curl/8.4.0';
    public const ODD = 'SomeApp/1.0 (internal)';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'cache.default' => 'array',
        ]);
        DB::purge('sqlite');

        foreach ([
            'packages/marvel/database/migrations/2026_07_04_000000_create_analytics_events_table.php',
            'packages/marvel/database/migrations/2026_07_04_000100_create_visitors_table.php',
            'packages/marvel/database/migrations/2026_08_04_000100_create_ip_locations_table.php',
            'packages/marvel/database/migrations/2026_10_07_000100_add_traffic_classification_to_analytics.php',
        ] as $file) {
            (require base_path($file))->up();
        }

        Schema::create('users', function ($t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->timestamps();
        });
        Schema::create('orders', function ($t) {
            $t->id();
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('tracking_number')->nullable();
            $t->string('order_status')->nullable();
            $t->string('payment_status')->nullable();
            $t->decimal('paid_total', 12, 2)->default(0);
            $t->timestamps();
            $t->timestamp('deleted_at')->nullable();
        });
    }

    /** POST a beacon the way the SDK does (text/plain JSON) or the proxy does (crawl route, JSON). */
    protected function ping(array $body, array $headers = [], bool $crawl = false): TestResponse
    {
        $headers = $headers + ['User-Agent' => self::CHROME]; // caller's UA wins (array union keeps the left side)
        $url = $crawl ? '/api/track/crawl' : '/api/track';
        if ($crawl) {
            return $this->postJson($url, $body, $headers);
        }
        return $this->call('POST', $url, [], [], [], $this->transformHeadersToServerVars(
            $headers + ['Content-Type' => 'text/plain']
        ), json_encode($body));
    }

    protected function pageView(string $visitorId, string $page = '/', array $extra = [], array $headers = []): TestResponse
    {
        return $this->ping([
            'visitor_id' => $visitorId,
            'session_id' => $extra['session_id'] ?? ('s_' . $visitorId),
            'page'       => $page,
            'events'     => [['type' => 'page_view', 'url' => $page]],
        ] + $extra, $headers);
    }

    protected function visitor(string $id): ?object
    {
        return DB::table('visitors')->where('visitor_id', $id)->first();
    }
}
