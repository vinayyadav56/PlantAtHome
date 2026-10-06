<?php

namespace Tests\Feature\Tracking;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/track — the contract is "always 204, never throw", and underneath it:
 * classify, store, separate. Bots are KEPT with their label; only business
 * counters (page_views from the wrong leg, user_id on a bot) are withheld.
 */
final class TrackingIngestTest extends TrackingTestCase
{
    public function test_a_new_browser_visitor_is_stored_and_classified_human(): void
    {
        $this->pageView('v_abcdef12', '/plants')->assertNoContent();

        $v = $this->visitor('v_abcdef12');
        $this->assertNotNull($v);
        $this->assertSame('human', $v->traffic_type);
        $this->assertNull($v->bot_name);
        $this->assertSame(1, (int) $v->page_views);
        $this->assertSame('/plants', $v->entry_page);
        $this->assertSame('Chrome', $v->browser);
        $this->assertSame(1, DB::table('analytics_sessions')->count());
        $this->assertSame(1, DB::table('analytics_events')->where('type', 'page_view')->where('traffic_type', 'human')->count());
    }

    public function test_the_same_cookie_is_the_same_visitor_and_page_views_accumulate(): void
    {
        $this->pageView('v_abcdef12', '/');
        $this->pageView('v_abcdef12', '/plants');

        $v = $this->visitor('v_abcdef12');
        $this->assertSame(2, (int) $v->page_views);
        $this->assertSame('/plants', $v->current_page);
        $this->assertSame('/', $v->previous_page);
        $this->assertSame(1, DB::table('visitors')->count(), 'one cookie = one visitor row');
        $this->assertSame(1, DB::table('analytics_sessions')->count(), 'same session id, no inactivity = one session');
        $this->assertSame(2, (int) DB::table('analytics_sessions')->value('page_views'));
    }

    public function test_a_heartbeat_touches_last_seen_and_nothing_else(): void
    {
        Carbon::setTestNow('2026-10-07 10:00:00');
        $this->pageView('v_abcdef12', '/plants');
        Carbon::setTestNow('2026-10-07 10:00:45');

        $this->ping(['visitor_id' => 'v_abcdef12', 'session_id' => 's_v_abcdef12', 'page' => '/plants', 'heartbeat' => true, 'events' => []])
            ->assertNoContent();

        $v = $this->visitor('v_abcdef12');
        $this->assertSame('2026-10-07 10:00:45', (string) $v->last_seen);
        $this->assertSame(1, (int) $v->page_views, 'a heartbeat is not a page view');
        $this->assertSame(1, DB::table('analytics_events')->count(), 'a heartbeat is not an event');
        $this->assertSame(1, DB::table('analytics_sessions')->count(), 'a heartbeat never opens a session');
        $s = DB::table('analytics_sessions')->first();
        $this->assertSame('2026-10-07 10:00:45', (string) $s->last_seen_at);
        $this->assertSame(1, (int) $s->engaged, '45 s on site is engaged');
        Carbon::setTestNow();
    }

    public function test_a_new_session_id_opens_a_second_session(): void
    {
        $this->pageView('v_abcdef12', '/', ['session_id' => 's_one00001']);
        $this->pageView('v_abcdef12', '/', ['session_id' => 's_two00002']);

        $this->assertSame(2, DB::table('analytics_sessions')->count());
        $this->assertSame(1, DB::table('visitors')->count());
    }

    public function test_a_stale_session_id_continues_as_a_new_row_after_thirty_minutes(): void
    {
        Carbon::setTestNow('2026-10-07 10:00:00');
        $this->pageView('v_abcdef12', '/', ['session_id' => 's_same00001']);
        Carbon::setTestNow('2026-10-07 10:20:00');
        $this->pageView('v_abcdef12', '/plants', ['session_id' => 's_same00001']);
        $this->assertSame(1, DB::table('analytics_sessions')->count(), '20 min of silence is the same session');

        Carbon::setTestNow('2026-10-07 10:51:00');
        $this->pageView('v_abcdef12', '/cart', ['session_id' => 's_same00001']);

        $this->assertSame(2, DB::table('analytics_sessions')->count(), '31 min of silence starts a new session under the same id');
        $latest = DB::table('analytics_sessions')->orderByDesc('id')->first();
        $this->assertSame('/cart', $latest->landing_page);
        $this->assertSame(1, (int) $latest->page_views);
        Carbon::setTestNow();
    }

    public function test_a_bot_that_runs_javascript_is_stored_as_a_bot_but_its_page_view_is_not_counted_twice(): void
    {
        $this->pageView('v_gbot0001', '/plants', [], ['User-Agent' => self::GOOGLEBOT])->assertNoContent();

        $v = $this->visitor('v_gbot0001');
        $this->assertNotNull($v, 'bots are tracked, never discarded');
        $this->assertSame('bot', $v->traffic_type);
        $this->assertSame('Googlebot', $v->bot_name);
        $this->assertSame('search', $v->bot_type);
        $this->assertSame(0, (int) $v->page_views, 'the crawl leg counts bot page views, the SDK leg does not');
        $this->assertSame('bot', DB::table('analytics_events')->value('traffic_type'));
    }

    public function test_the_crawl_leg_records_a_non_js_crawler_with_geo_and_counts_its_page_view(): void
    {
        $this->ping([
            'page' => '/plants/monstera', 'referrer' => null, 'ip' => '66.249.66.1',
            'country_code' => 'us', 'state' => 'CA', 'city' => 'Mountain View',
            'source' => 'server', 'events' => [['type' => 'page_view', 'url' => '/plants/monstera']],
        ], ['User-Agent' => self::GPTBOT], crawl: true)->assertNoContent();

        $v = DB::table('visitors')->first();
        $this->assertStringStartsWith('b:', $v->visitor_id);
        $this->assertSame('bot', $v->traffic_type);
        $this->assertSame('GPTBot', $v->bot_name);
        $this->assertSame('ai', $v->bot_type);
        $this->assertSame(1, (int) $v->page_views);
        $this->assertSame('US', $v->country_code);
        $this->assertSame('Mountain View', $v->city);
        $this->assertSame('66.249.66.1', $v->ip);
        $this->assertSame(1, DB::table('analytics_sessions')->where('traffic_type', 'bot')->where('page_views', 1)->count());

        // Same crawler, same IP, second page → same visitor, two page views.
        $this->ping(['page' => '/cart', 'ip' => '66.249.66.1', 'events' => [['type' => 'page_view']]], ['User-Agent' => self::GPTBOT], crawl: true);
        $this->assertSame(1, DB::table('visitors')->count());
        $this->assertSame(2, (int) DB::table('visitors')->value('page_views'));
    }

    public function test_a_human_user_agent_on_the_crawl_leg_is_not_counted_as_a_page_view(): void
    {
        $this->ping(['page' => '/', 'ip' => '1.2.3.4', 'events' => [['type' => 'page_view']]], ['User-Agent' => self::CHROME], crawl: true)->assertNoContent();

        $v = DB::table('visitors')->first();
        $this->assertSame('human', $v->traffic_type);
        $this->assertSame(0, (int) $v->page_views, 'the SDK counts human page views');
    }

    public function test_a_generic_automation_user_agent_is_a_bot_and_an_odd_one_is_unknown(): void
    {
        $this->pageView('v_curl0001', '/', [], ['User-Agent' => self::CURL]);
        $this->pageView('v_odd00001', '/', [], ['User-Agent' => self::ODD]);

        $this->assertSame('bot', $this->visitor('v_curl0001')->traffic_type);
        $this->assertSame('Curl', $this->visitor('v_curl0001')->bot_name);
        $this->assertSame('other', $this->visitor('v_curl0001')->bot_type);
        $this->assertSame('unknown', $this->visitor('v_odd00001')->traffic_type);
        $this->assertSame(1, (int) $this->visitor('v_odd00001')->page_views, 'unknown traffic from the SDK is counted');
    }

    public function test_a_bot_never_becomes_a_customer_whatever_user_id_it_sends(): void
    {
        $this->pageView('v_gbot0001', '/', ['user_id' => 42], ['User-Agent' => self::GOOGLEBOT]);
        $this->pageView('v_human001', '/', ['user_id' => 42]);

        $this->assertNull($this->visitor('v_gbot0001')->user_id);
        $this->assertSame(42, (int) $this->visitor('v_human001')->user_id);
        $this->assertNull(DB::table('analytics_sessions')->where('traffic_type', 'bot')->value('user_id'));
    }

    public function test_first_touch_utm_is_kept_when_a_later_visit_carries_different_utm(): void
    {
        $this->pageView('v_abcdef12', '/', ['session_id' => 's_one00001', 'utm' => ['utm_source' => 'google', 'utm_medium' => 'cpc']]);
        $this->pageView('v_abcdef12', '/', ['session_id' => 's_two00002', 'utm' => ['utm_source' => 'instagram']]);

        $v = $this->visitor('v_abcdef12');
        $this->assertSame('google', $v->utm_source, 'visitor keeps the first touch');
        $this->assertSame('cpc', $v->utm_medium);
        $sessions = DB::table('analytics_sessions')->orderBy('id')->pluck('utm_source')->all();
        $this->assertSame(['google', 'instagram'], $sessions, 'each session keeps its own touch');
    }

    public function test_a_session_without_utm_inherits_the_visitors_first_touch(): void
    {
        $this->pageView('v_abcdef12', '/', ['session_id' => 's_one00001', 'utm' => ['utm_source' => 'google']]);
        $this->pageView('v_abcdef12', '/', ['session_id' => 's_two00002']);

        $this->assertSame('google', DB::table('analytics_sessions')->orderByDesc('id')->value('utm_source'));
    }

    public function test_query_strings_and_fragments_never_reach_storage(): void
    {
        $this->ping([
            'visitor_id' => 'v_abcdef12', 'session_id' => 's_v', 'page' => '/reset-password?token=SECRET#x',
            'events' => [['type' => 'page_view', 'url' => '/reset-password?token=SECRET'], ['type' => 'search', 'url' => '/plants/search?text=fern', 'label' => 'fern']],
        ]);

        $this->assertSame('/reset-password', $this->visitor('v_abcdef12')->current_page);
        $this->assertSame('/reset-password', DB::table('analytics_sessions')->value('landing_page'));
        foreach (DB::table('analytics_events')->pluck('url') as $url) {
            $this->assertStringNotContainsString('?', $url);
            $this->assertStringNotContainsString('SECRET', $url);
        }
        $this->assertSame('fern', DB::table('analytics_events')->where('type', 'search')->value('label'));
    }

    public function test_meta_is_whitelisted_capped_and_always_valid_json(): void
    {
        $this->ping([
            'visitor_id' => 'v_abcdef12', 'session_id' => 's_v', 'page' => '/products/x',
            'events' => [['type' => 'product_view', 'label' => 'Monstera', 'meta' => [
                'product_id' => 123, 'category' => str_repeat('c', 3000), 'password' => 'nope', 'nested' => ['a' => 1],
            ]]],
        ])->assertNoContent();

        $row = DB::table('analytics_events')->where('type', 'product_view')->first();
        $this->assertNotNull($row, 'a large meta must not break the insert');
        $meta = json_decode($row->meta, true);
        $this->assertIsArray($meta, 'meta is always valid JSON (the column is json on MySQL)');
        $this->assertSame(123, $meta['product_id']);
        $this->assertSame(255, mb_strlen($meta['category']));
        $this->assertArrayNotHasKey('password', $meta);
        $this->assertArrayNotHasKey('nested', $meta);
    }

    public function test_oversized_malformed_and_invalid_payloads_are_dropped_with_204(): void
    {
        $this->ping(['visitor_id' => 'v_abcdef12', 'pad' => str_repeat('x', 20000)])->assertNoContent();
        $this->call('POST', '/api/track', [], [], [], $this->transformHeadersToServerVars(['Content-Type' => 'text/plain', 'User-Agent' => self::CHROME]), '{not json')->assertNoContent();
        $this->ping(['session_id' => 's_v', 'events' => [['type' => 'page_view']]])->assertNoContent();   // no visitor_id
        $this->ping(['visitor_id' => 'bad id!', 'events' => [['type' => 'page_view']]])->assertNoContent();
        $this->ping(['visitor_id' => 'v_abcdef12', 'events' => 'nope'])->assertNoContent();

        $this->assertSame(0, DB::table('analytics_events')->count());
        $this->assertSame(1, DB::table('visitors')->count(), 'the last ping had a valid id and simply no events');
    }

    public function test_unknown_event_types_are_dropped_aliases_are_canonicalised_and_the_count_is_capped(): void
    {
        $events = [['type' => 'checkout_start'], ['type' => 'payment_complete'], ['type' => 'drop_table'], ['type' => 'script_injection']];
        for ($i = 0; $i < 25; $i++) {
            $events[] = ['type' => 'page_view'];
        }
        $this->ping(['visitor_id' => 'v_abcdef12', 'session_id' => 's_v', 'page' => '/checkout', 'events' => $events]);

        $types = DB::table('analytics_events')->pluck('type');
        $this->assertTrue($types->contains('begin_checkout'));
        $this->assertTrue($types->contains('order_success'));
        $this->assertFalse($types->contains('drop_table'));
        $this->assertFalse($types->contains('checkout_start'));
        $this->assertLessThanOrEqual(20, $types->count());
    }

    public function test_geo_comes_from_cloudflare_headers_for_the_sdk_leg(): void
    {
        $this->pageView('v_abcdef12', '/', [], ['cf-ipcountry' => 'IN', 'cf-ipcity' => 'Gurugram', 'cf-region-code' => 'HR', 'CF-Connecting-IP' => '49.36.1.1']);

        $v = $this->visitor('v_abcdef12');
        $this->assertSame('IN', $v->country_code);
        $this->assertSame('Gurugram', $v->city);
        $this->assertSame('HR', $v->state);
        $this->assertSame('49.36.1.1', $v->ip);
    }

    public function test_geo_falls_back_to_ip_locations_and_survives_having_none(): void
    {
        DB::table('ip_locations')->insert(['ip' => '49.36.1.1', 'country_code' => 'IN', 'country' => 'India', 'region' => 'Delhi', 'city' => 'New Delhi', 'looked_up_at' => now()]);
        $this->pageView('v_abcdef12', '/', [], ['CF-Connecting-IP' => '49.36.1.1']);
        $this->pageView('v_nowhere1', '/', [], ['CF-Connecting-IP' => '10.0.0.9']);

        $this->assertSame('New Delhi', $this->visitor('v_abcdef12')->city);
        $this->assertSame('India', $this->visitor('v_abcdef12')->country);
        $this->assertNull($this->visitor('v_nowhere1')->country_code, 'no geo is fine');
        $this->assertSame(1, (int) $this->visitor('v_nowhere1')->page_views, 'and the ping still counted');
    }

    public function test_the_shopping_city_and_cloudflare_unknown_country_are_handled(): void
    {
        $this->pageView('v_abcdef12', '/', ['shopping_city' => 'Gurugram'], ['cf-ipcountry' => 'XX']);

        $v = $this->visitor('v_abcdef12');
        $this->assertSame('Gurugram', $v->shopping_city);
        $this->assertNull($v->country_code, 'XX is "unknown", not a country');
    }

    public function test_json_content_type_beacons_still_work(): void
    {
        $this->postJson('/api/track', ['visitor_id' => 'v_abcdef12', 'page' => '/', 'events' => [['type' => 'page_view']]], ['User-Agent' => self::SAFARI_IOS])
            ->assertNoContent();

        $v = $this->visitor('v_abcdef12');
        $this->assertSame('human', $v->traffic_type);
        $this->assertSame('mobile', $v->device);
        $this->assertSame('iOS', $v->os);
    }
}
