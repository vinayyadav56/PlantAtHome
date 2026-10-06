<?php

namespace Tests\Feature\Tracking;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Marvel\Services\VisitorMetricsService;

/**
 * The ONE definition of online / customers / engaged / funnel, and the report
 * that feeds both the live TODAY strip and the history page. Fed through the
 * real ingest so the numbers are what the admin will see.
 */
final class VisitorMetricsTest extends TrackingTestCase
{
    private VisitorMetricsService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(VisitorMetricsService::class);
    }

    public function test_online_is_split_by_traffic_type_and_customers_guests_engaged_are_humans_only(): void
    {
        Carbon::setTestNow('2026-10-07 10:00:00');
        $this->pageView('v_human001', '/', ['user_id' => 7]);
        $this->pageView('v_human002', '/');
        $this->pageView('v_human003', '/');
        $this->pageView('v_gbot0001', '/', ['user_id' => 9], ['User-Agent' => self::GOOGLEBOT]);
        $this->pageView('v_odd00001', '/', [], ['User-Agent' => self::ODD]);
        // v_human003 engages: a second page view
        $this->pageView('v_human003', '/plants');

        $c = $this->svc->onlineCounts();
        $this->assertSame(['total' => 5, 'human' => 3, 'bot' => 1, 'unknown' => 1, 'customers' => 1, 'guests' => 2, 'engaged' => 1], $c);

        $live = $this->svc->live();
        $this->assertSame(120, $live['window_sec']);
        $this->assertSame(3, $live['online']['human']);
        $this->assertCount(5, $live['visitors']);
        $this->assertCount(1, $this->svc->live('bot')['visitors']);
        $bot = $this->svc->live('bot')['visitors'][0];
        $this->assertSame('Googlebot', $bot['bot_name']);
        $this->assertFalse($bot['is_customer']);
        $this->assertNotNull($bot['user_agent'], 'the UA is shown for non-humans');
        $this->assertNull($this->svc->live('human')['visitors'][0]['user_agent']);
        Carbon::setTestNow();
    }

    public function test_an_inactive_visitor_drops_off_and_a_heartbeat_keeps_one_online(): void
    {
        Carbon::setTestNow('2026-10-07 10:00:00');
        $this->pageView('v_stayer01', '/');
        $this->pageView('v_leaver01', '/');

        Carbon::setTestNow('2026-10-07 10:01:30');
        $this->ping(['visitor_id' => 'v_stayer01', 'session_id' => 's_v_stayer01', 'page' => '/', 'heartbeat' => true, 'events' => []]);

        Carbon::setTestNow('2026-10-07 10:03:00');   // 3 min after the page views, 90 s after the beat
        $c = $this->svc->onlineCounts();
        $this->assertSame(1, $c['human']);
        $this->assertSame('v_stayer01', $this->svc->live()['visitors'][0]['visitor_id']);
        Carbon::setTestNow();
    }

    public function test_the_funnel_counts_humans_only(): void
    {
        foreach (['v_human001', 'v_human002'] as $v) {
            $this->ping(['visitor_id' => $v, 'session_id' => "s_$v", 'page' => '/products/m', 'events' => [
                ['type' => 'page_view'], ['type' => 'product_view', 'label' => 'Monstera'], ['type' => 'add_to_cart'],
            ]]);
        }
        $this->ping(['visitor_id' => 'v_human001', 'session_id' => 's_v_human001', 'page' => '/checkout', 'events' => [
            ['type' => 'begin_checkout'], ['type' => 'payment_initiated'], ['type' => 'payment_success'], ['type' => 'order_created'],
        ]]);
        // A crawler "doing" the whole funnel must change nothing.
        $this->ping(['visitor_id' => 'v_gbot0001', 'session_id' => 's_b', 'page' => '/checkout', 'events' => [
            ['type' => 'page_view'], ['type' => 'product_view'], ['type' => 'add_to_cart'], ['type' => 'order_created'],
        ]], ['User-Agent' => self::GOOGLEBOT]);

        $f = $this->svc->funnel(1);
        $this->assertSame('human', $f['traffic_type']);
        $this->assertSame(2, $f['visitors']);
        $this->assertSame(2, $f['product_view']);
        $this->assertSame(2, $f['add_to_cart']);
        $this->assertSame(1, $f['begin_checkout']);
        $this->assertSame(1, $f['order_created']);
        $this->assertSame(50.0, $f['conversion_pct']);
        $this->assertSame('product_view', $f['steps'][0]['key']);
    }

    public function test_the_report_splits_totals_by_day_and_lists_bots_cities_sources_and_products(): void
    {
        Carbon::setTestNow('2026-10-06 09:00:00');
        $this->pageView('v_human001', '/plants', ['utm' => ['utm_source' => 'google'], 'shopping_city' => 'Gurugram']);
        $this->ping(['page' => '/plants', 'ip' => '66.249.66.1', 'country_code' => 'US', 'events' => [['type' => 'page_view']]], ['User-Agent' => self::GPTBOT], crawl: true);

        Carbon::setTestNow('2026-10-07 09:00:00');
        $this->ping(['visitor_id' => 'v_human002', 'session_id' => 's_2', 'page' => '/products/monstera', 'referrer' => 'https://www.instagram.com/p/x', 'shopping_city' => 'Delhi', 'user_id' => 5, 'events' => [
            ['type' => 'page_view'], ['type' => 'product_view', 'label' => 'Monstera'], ['type' => 'order_created'],
        ]]);
        $this->ping(['page' => '/cart', 'ip' => '66.249.66.2', 'country_code' => 'US', 'events' => [['type' => 'page_view']]], ['User-Agent' => self::GOOGLEBOT], crawl: true);
        $this->pageView('v_odd00001', '/', [], ['User-Agent' => self::ODD]);
        DB::table('users')->insert(['id' => 5, 'name' => 'Asha', 'email' => 'asha@example.com']);
        DB::table('orders')->insert(['parent_id' => null, 'customer_id' => 5, 'order_status' => 'order-processing', 'payment_status' => 'payment-success', 'paid_total' => 899, 'created_at' => now(), 'updated_at' => now()]);

        $r = $this->svc->report(7);
        $this->assertSame(7, $r['days']);
        $this->assertSame(['total' => 5, 'human' => 2, 'bot' => 2, 'unknown' => 1], $r['totals']['sessions']);
        $this->assertSame(['total' => 5, 'human' => 2, 'bot' => 2, 'unknown' => 1], $r['totals']['page_views']);
        $this->assertCount(7, $r['by_day']);
        $yesterday = collect($r['by_day'])->firstWhere('date', '2026-10-06');
        $today = collect($r['by_day'])->firstWhere('date', '2026-10-07');
        $this->assertSame(1, $yesterday['human_sessions']);
        $this->assertSame(1, $yesterday['bot_sessions']);
        $this->assertSame(1, $today['bot_sessions']);
        $this->assertSame(1, $today['unknown_sessions']);

        $this->assertSame(2, $r['human']['visitors']);
        $this->assertSame(1, $r['human']['product_views']);
        $this->assertSame(1, $r['human']['orders'], 'orders come from the order book');
        $this->assertSame(899.0, $r['human']['revenue']);
        $this->assertSame(50.0, $r['human']['conversion_pct']);
        $this->assertSame(['Delhi', 'Gurugram'], collect($r['top_cities'])->pluck('city')->sort()->values()->all());
        $this->assertSame(['google', 'instagram.com'], collect($r['sources'])->pluck('source')->sort()->values()->all());
        $this->assertSame('Monstera', $r['top_products'][0]['label']);
        $this->assertSame('asha@example.com', $r['converted_users'][0]['email']);

        $this->assertSame(2, $r['bots']['visitors']);
        $names = collect($r['bots']['top_bots'])->pluck('name')->sort()->values()->all();
        $this->assertSame(['GPTBot', 'Googlebot'], $names);
        $this->assertSame('ai', collect($r['bots']['top_bots'])->firstWhere('name', 'GPTBot')['type']);
        $this->assertSame('US', $r['bots']['by_country'][0]['country']);
        $this->assertCount(2, $r['bots']['top_pages']);
        $this->assertCount(2, $r['bots']['by_user_agent']);

        // days=1 is today only
        $t = $this->svc->report(1);
        $this->assertSame(['total' => 3, 'human' => 1, 'bot' => 1, 'unknown' => 1], $t['totals']['sessions']);
        Carbon::setTestNow();
    }

    public function test_the_journey_includes_sessions(): void
    {
        $this->pageView('v_human001', '/', ['session_id' => 's_one00001']);
        $this->pageView('v_human001', '/', ['session_id' => 's_two00002']);

        $j = $this->svc->visitorJourney('v_human001');
        $this->assertCount(2, $j['sessions']);
        $this->assertSame('human', $j['visitor']->traffic_type);
    }
}
