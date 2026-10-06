<?php

namespace Marvel\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\AnalyticsEvent;
use Marvel\Database\Models\AnalyticsSession;
use Marvel\Database\Models\User;
use Marvel\Database\Models\Visitor;
use Marvel\Enums\Permission;
use Marvel\Services\Tracking\TrafficType;

/**
 * THE source of truth for visitor numbers. Every "online", "visitors today",
 * funnel and traffic report the admin shows comes from here — the request_logs
 * IP approximation and the second 5-minute count that used to disagree with
 * this one are gone.
 *
 * Definitions (config/tracking.php has the long form):
 *   online   = visitors.last_seen within tracking.online_timeout_sec (120 s)
 *   session  = an analytics_sessions row; new after 30 min of silence
 *   engaged  = human session: 2+ page views, 30 s+ on site, or a product_view /
 *              add_to_cart / begin_checkout
 *   customer = online HUMAN with a user_id; guest = online human without
 *   business metrics (funnel, conversion, sources, cities) = HUMAN only
 *   total    = human + bot + unknown
 *
 * Every read is failure-safe (missing tables → zeros) so the NOC renders even
 * before a migration lands.
 */
class VisitorMetricsService
{
    public function windowSec(): int
    {
        return (int) config('tracking.online_timeout_sec', 120);
    }

    /**
     * @return array{total:int,human:int,bot:int,unknown:int,customers:int,guests:int,engaged:int}
     */
    public function onlineCounts(): array
    {
        $out = ['total' => 0, 'human' => 0, 'bot' => 0, 'unknown' => 0, 'customers' => 0, 'guests' => 0, 'engaged' => 0];
        try {
            $since = Carbon::now()->subSeconds($this->windowSec());
            $byType = Visitor::where('last_seen', '>=', $since)
                ->select('traffic_type', DB::raw('COUNT(*) as c'))
                ->groupBy('traffic_type')->pluck('c', 'traffic_type');
            foreach (TrafficType::ALL as $t) {
                $out[$t] = (int) ($byType[$t] ?? 0);
            }
            $out['total'] = $out['human'] + $out['bot'] + $out['unknown'];
            $out['customers'] = (int) Visitor::where('last_seen', '>=', $since)
                ->where('traffic_type', TrafficType::HUMAN)->whereNotNull('user_id')->count();
            $out['guests'] = max(0, $out['human'] - $out['customers']);
            $out['engaged'] = (int) AnalyticsSession::where('last_seen_at', '>=', $since)
                ->where('traffic_type', TrafficType::HUMAN)->where('engaged', true)
                ->distinct()->count('visitor_id');
        } catch (\Throwable) {
            // tables not migrated yet — zeros
        }
        return $out;
    }

    /** Who's online now + the live table. ?type= human|bot|unknown filters the rows only. */
    public function live(?string $type = null): array
    {
        $counts = $this->onlineCounts();
        $since = Carbon::now()->subSeconds($this->windowSec());
        $type = TrafficType::valid($type) ? $type : null;

        $byDevice = [];
        $visitors = collect();
        try {
            $byDevice = Visitor::where('last_seen', '>=', $since)->where('traffic_type', TrafficType::HUMAN)
                ->select('device', DB::raw('COUNT(*) as c'))->groupBy('device')->pluck('c', 'device');

            $rows = Visitor::where('last_seen', '>=', $since)
                ->when($type, fn ($q) => $q->where('traffic_type', $type))
                ->orderByDesc('last_seen')->limit(60)->get();

            // Time on site = the CURRENT session, not first_seen→last_seen (90-day retention
            // would turn that into "time since we first met you").
            $sessions = AnalyticsSession::whereIn('visitor_id', $rows->pluck('visitor_id'))
                ->orderByDesc('id')->get()->unique('visitor_id')->keyBy('visitor_id');

            $visitors = $rows->map(function (Visitor $v) use ($sessions) {
                $s = $sessions[$v->visitor_id] ?? null;
                $human = $v->traffic_type === TrafficType::HUMAN;
                return [
                    'visitor_id'       => $v->visitor_id,
                    'traffic_type'     => $v->traffic_type ?? TrafficType::UNKNOWN,
                    'bot_name'         => $v->bot_name,
                    'bot_type'         => $v->bot_type,
                    'is_customer'      => $human && !is_null($v->user_id),
                    'engaged'          => (bool) $v->engaged,
                    'current_page'     => $v->current_page,
                    'previous_page'    => $v->previous_page,
                    'device'           => $v->device,
                    'browser'          => $v->browser,
                    'os'               => $v->os,
                    'city'             => $v->city,
                    'country'          => $v->country_code ?: $v->country,
                    'shopping_city'    => $v->shopping_city,
                    'page_views'       => (int) ($s->page_views ?? $v->page_views),
                    'time_on_site_sec' => $s && $s->started_at && $s->last_seen_at ? $s->last_seen_at->diffInSeconds($s->started_at) : 0,
                    'first_seen'       => $v->first_seen,
                    'last_seen'        => $v->last_seen,
                    // The UA is only interesting for non-humans (which crawler, exactly).
                    'user_agent'       => $human ? null : $v->user_agent,
                ];
            })->values();
        } catch (\Throwable) {
            // tables not migrated yet
        }

        return [
            'window_sec' => $this->windowSec(),
            'online'     => ['total' => $counts['total'], 'human' => $counts['human'], 'bot' => $counts['bot'], 'unknown' => $counts['unknown']],
            'human'      => ['customers' => $counts['customers'], 'guests' => $counts['guests'], 'engaged' => $counts['engaged']],
            'by_device'  => $byDevice,
            'visitors'   => $visitors,
            // kept for older admin bundles
            'customers'  => $counts['customers'],
            'guests'     => $counts['guests'],
        ];
    }

    /** Backwards-compatible name. */
    public function liveVisitors(): array
    {
        return $this->live();
    }

    /** The page/event timeline for one visitor (customer journey) + their sessions. */
    public function visitorJourney(string $visitorId): array
    {
        $visitor = Visitor::where('visitor_id', $visitorId)->first();
        $events = AnalyticsEvent::where('visitor_id', $visitorId)
            ->orderByDesc('id')->limit(120)
            ->get(['type', 'url', 'label', 'value', 'meta', 'created_at'])
            ->reverse()->values();
        $sessions = collect();
        try {
            $sessions = AnalyticsSession::where('visitor_id', $visitorId)->orderByDesc('id')->limit(20)->get();
        } catch (\Throwable) {
        }

        // "Maximum data" (live-visitors annotation): when the visitor is signed in, the
        // dossier should say WHO — identity plus their relationship with the store. All
        // cheap indexed lookups; a guest simply gets user: null.
        $user = null;
        if ($visitor?->user_id) {
            $u = User::with('profile')->find($visitor->user_id);
            if ($u) {
                $orders = DB::table('orders')
                    ->whereNull('parent_id')->whereNull('deleted_at')
                    ->where('customer_id', $u->id)
                    ->whereNotIn('order_status', ['order-cancelled', 'order-failed']);
                $user = [
                    'id'            => $u->id,
                    'name'          => $u->name,
                    'email'         => $u->email,
                    'contact'       => optional($u->profile)->contact,
                    'orders_count'  => (int) (clone $orders)->count(),
                    'total_spent'   => (float) (clone $orders)->sum('paid_total'),
                    'last_order_at' => (clone $orders)->max('created_at'),
                    'member_since'  => optional($u->created_at)->toDateString(),
                ];
            }
        }

        return ['visitor' => $visitor, 'user' => $user, 'events' => $events, 'sessions' => $sessions];
    }

    /**
     * Purchase funnel over the window — HUMAN visitors only, so a crawl can never
     * look like a conversion collapse. Distinct visitors per step.
     */
    public function funnel(int $days = 1): array
    {
        $since = Carbon::now()->subDays(max(1, $days));
        $visitors = 0;
        $counts = collect();
        try {
            $visitors = (int) Visitor::where('traffic_type', TrafficType::HUMAN)->where('last_seen', '>=', $since)->count();
            $counts = AnalyticsEvent::where('traffic_type', TrafficType::HUMAN)
                ->where('created_at', '>=', $since)
                ->whereIn('type', AnalyticsEvent::FUNNEL_STEPS)
                ->select('type', DB::raw('COUNT(DISTINCT visitor_id) as c'))
                ->groupBy('type')->pluck('c', 'type');
        } catch (\Throwable) {
        }

        $out = ['traffic_type' => TrafficType::HUMAN, 'days' => $days, 'visitors' => $visitors, 'steps' => []];
        $labels = [
            'product_view' => 'Product views', 'add_to_cart' => 'Add to cart', 'view_cart' => 'View cart',
            'begin_checkout' => 'Begin checkout', 'add_address' => 'Add address', 'payment_initiated' => 'Payment initiated',
            'payment_success' => 'Payment success', 'order_created' => 'Order created',
        ];
        foreach (AnalyticsEvent::FUNNEL_STEPS as $step) {
            $out[$step] = (int) ($counts[$step] ?? 0);
            $out['steps'][] = ['key' => $step, 'label' => $labels[$step], 'count' => $out[$step]];
        }
        // kept for older admin bundles
        $out['product_views'] = $out['product_view'];
        $out['checkout_start'] = $out['begin_checkout'];
        $out['payment_complete'] = $out['order_created'];
        $out['conversion_pct'] = $visitors > 0 ? round(($out['order_created'] / $visitors) * 100, 2) : 0;
        return $out;
    }

    /**
     * Historical traffic report. days ∈ {1, 7, 30}; "1" is today and backs the live
     * page's TODAY strip (30 s cache), the others cache for 5 minutes.
     */
    public function report(int $days = 7): array
    {
        $days = in_array($days, [1, 7, 30], true) ? $days : 7;
        try {
            return Cache::remember("cc_traffic_report:$days", $days === 1 ? 30 : 300, fn () => $this->buildReport($days));
        } catch (\Throwable) {
            return $this->buildReport($days);
        }
    }

    private function buildReport(int $days): array
    {
        $since = Carbon::today()->subDays($days - 1);
        $zero = fn () => ['total' => 0, 'human' => 0, 'bot' => 0, 'unknown' => 0];
        $out = [
            'days' => $days, 'since' => $since->toDateString(),
            'totals' => ['visitors' => $zero(), 'sessions' => $zero(), 'page_views' => $zero()],
            'by_day' => [],
            'human' => ['visitors' => 0, 'sessions' => 0, 'page_views' => 0, 'product_views' => 0, 'add_to_carts' => 0,
                'checkouts' => 0, 'tracked_orders' => 0, 'orders' => 0, 'revenue' => 0.0, 'conversion_pct' => 0],
            'top_pages' => [], 'top_cities' => [], 'sources' => [], 'top_products' => [], 'converted_users' => [],
            'bots' => ['visitors' => 0, 'sessions' => 0, 'page_views' => 0, 'top_bots' => [], 'top_pages' => [], 'by_country' => [], 'by_user_agent' => []],
        ];
        if (!Schema::hasTable('analytics_sessions')) {
            return $out;
        }

        $sessions = fn () => AnalyticsSession::where('started_at', '>=', $since);
        $events = fn (string $type) => AnalyticsEvent::where('traffic_type', $type)->where('created_at', '>=', $since);

        // Totals, one GROUP BY.
        foreach ($sessions()->select('traffic_type', DB::raw('COUNT(*) as s'), DB::raw('COALESCE(SUM(page_views),0) as pv'), DB::raw('COUNT(DISTINCT visitor_id) as v'))
            ->groupBy('traffic_type')->get() as $r) {
            $t = TrafficType::valid($r->traffic_type) ? $r->traffic_type : TrafficType::UNKNOWN;
            $out['totals']['sessions'][$t] += (int) $r->s;
            $out['totals']['page_views'][$t] += (int) $r->pv;
            $out['totals']['visitors'][$t] += (int) $r->v;
        }
        foreach (['visitors', 'sessions', 'page_views'] as $k) {
            $out['totals'][$k]['total'] = $out['totals'][$k]['human'] + $out['totals'][$k]['bot'] + $out['totals'][$k]['unknown'];
        }

        // By day, zero-filled. DATE() works on MySQL and sqlite (DATE_FORMAT does not).
        $days_ = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $since->copy()->addDays($i)->toDateString();
            $days_[$d] = ['date' => $d, 'human_sessions' => 0, 'bot_sessions' => 0, 'unknown_sessions' => 0, 'human_pv' => 0, 'bot_pv' => 0, 'unknown_pv' => 0];
        }
        foreach ($sessions()->select(DB::raw('DATE(started_at) as d'), 'traffic_type', DB::raw('COUNT(*) as s'), DB::raw('COALESCE(SUM(page_views),0) as pv'))
            ->groupBy('d', 'traffic_type')->get() as $r) {
            $t = TrafficType::valid($r->traffic_type) ? $r->traffic_type : TrafficType::UNKNOWN;
            if (isset($days_[$r->d])) {
                $days_[$r->d]["{$t}_sessions"] += (int) $r->s;
                $days_[$r->d]["{$t}_pv"] += (int) $r->pv;
            }
        }
        $out['by_day'] = array_values($days_);

        // ── Human (business) traffic ────────────────────────────────────────
        $h = &$out['human'];
        $h['visitors'] = $out['totals']['visitors']['human'];
        $h['sessions'] = $out['totals']['sessions']['human'];
        $h['page_views'] = $out['totals']['page_views']['human'];
        $steps = $events(TrafficType::HUMAN)->whereIn('type', ['product_view', 'add_to_cart', 'begin_checkout', 'order_created'])
            ->select('type', DB::raw('COUNT(DISTINCT visitor_id) as c'))->groupBy('type')->pluck('c', 'type');
        $h['product_views'] = (int) ($steps['product_view'] ?? 0);
        $h['add_to_carts'] = (int) ($steps['add_to_cart'] ?? 0);
        $h['checkouts'] = (int) ($steps['begin_checkout'] ?? 0);
        $h['tracked_orders'] = (int) ($steps['order_created'] ?? 0);
        // Money comes from the order book, never from browser-sent values.
        try {
            $orders = MetricsService::applyRevenueFilter(DB::table('orders')->whereNull('deleted_at')->whereNull('parent_id'))
                ->where('created_at', '>=', $since);
            $h['orders'] = (int) (clone $orders)->count();
            $h['revenue'] = round((float) (clone $orders)->sum('paid_total'), 2);
        } catch (\Throwable) {
        }
        $h['conversion_pct'] = $h['visitors'] > 0 ? round(($h['orders'] / $h['visitors']) * 100, 2) : 0;
        unset($h);

        $out['top_pages'] = $events(TrafficType::HUMAN)->where('type', 'page_view')->whereNotNull('url')
            ->select('url', DB::raw('COUNT(*) as views'), DB::raw('COUNT(DISTINCT visitor_id) as visitors'))
            ->groupBy('url')->orderByDesc('views')->limit(10)->get()
            ->map(fn ($r) => ['url' => $r->url, 'views' => (int) $r->views, 'visitors' => (int) $r->visitors])->all();

        $cityExpr = "COALESCE(NULLIF(visitors.shopping_city,''), NULLIF(visitors.city,''), 'Unknown')";
        $out['top_cities'] = DB::table('analytics_sessions')
            ->join('visitors', 'visitors.visitor_id', '=', 'analytics_sessions.visitor_id')
            ->where('analytics_sessions.traffic_type', TrafficType::HUMAN)->where('analytics_sessions.started_at', '>=', $since)
            ->select(DB::raw("$cityExpr as city"), DB::raw('COUNT(DISTINCT analytics_sessions.visitor_id) as visitors'), DB::raw('COUNT(*) as sessions'))
            ->groupBy(DB::raw($cityExpr))->orderByDesc('visitors')->limit(10)->get()
            ->map(fn ($r) => ['city' => $r->city, 'visitors' => (int) $r->visitors, 'sessions' => (int) $r->sessions])->all();

        // Sources: utm_source wins, else the referrer's host, else direct. Host parsing in PHP.
        $sources = [];
        foreach ($sessions()->where('traffic_type', TrafficType::HUMAN)
            ->select('utm_source', 'referrer', DB::raw('COUNT(*) as sessions'), DB::raw('COUNT(DISTINCT visitor_id) as visitors'))
            ->groupBy('utm_source', 'referrer')->get() as $r) {
            $key = $r->utm_source ?: (strtolower((string) parse_url((string) $r->referrer, PHP_URL_HOST)) ?: 'direct');
            $key = preg_replace('/^www\./', '', $key);
            $sources[$key] = ['source' => $key, 'sessions' => ($sources[$key]['sessions'] ?? 0) + (int) $r->sessions, 'visitors' => ($sources[$key]['visitors'] ?? 0) + (int) $r->visitors];
        }
        usort($sources, fn ($a, $b) => $b['sessions'] <=> $a['sessions']);
        $out['sources'] = array_slice(array_values($sources), 0, 10);

        $out['top_products'] = $events(TrafficType::HUMAN)->where('type', 'product_view')->whereNotNull('url')
            ->select('url', DB::raw('MAX(label) as label'), DB::raw('COUNT(*) as views'), DB::raw('COUNT(DISTINCT visitor_id) as visitors'))
            ->groupBy('url')->orderByDesc('views')->limit(10)->get()
            ->map(fn ($r) => ['url' => $r->url, 'label' => $r->label, 'views' => (int) $r->views, 'visitors' => (int) $r->visitors])->all();

        try {
            // Columns qualified: users has created_at too, and an ambiguous column kills the whole query.
            $out['converted_users'] = DB::table('analytics_events')
                ->where('analytics_events.traffic_type', TrafficType::HUMAN)->where('analytics_events.created_at', '>=', $since)
                ->where('analytics_events.type', 'order_created')->whereNotNull('analytics_events.user_id')
                ->join('users', 'users.id', '=', 'analytics_events.user_id')
                ->select('users.id', 'users.name', 'users.email', DB::raw('COUNT(*) as orders'), DB::raw('MAX(analytics_events.created_at) as last_order_at'))
                ->groupBy('users.id', 'users.name', 'users.email')->orderByDesc('last_order_at')->limit(20)->get()
                ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'email' => $r->email, 'orders' => (int) $r->orders, 'last_order_at' => $r->last_order_at])->all();
        } catch (\Throwable) {
        }

        // ── Bot traffic ─────────────────────────────────────────────────────
        $b = &$out['bots'];
        $b['visitors'] = $out['totals']['visitors']['bot'];
        $b['sessions'] = $out['totals']['sessions']['bot'];
        $b['page_views'] = $out['totals']['page_views']['bot'];
        $b['top_bots'] = DB::table('analytics_sessions')
            ->join('visitors', 'visitors.visitor_id', '=', 'analytics_sessions.visitor_id')
            ->where('analytics_sessions.traffic_type', TrafficType::BOT)->where('analytics_sessions.started_at', '>=', $since)
            ->select(DB::raw("COALESCE(analytics_sessions.bot_name,'Other') as name"), DB::raw('MAX(visitors.bot_type) as type'),
                DB::raw('COUNT(DISTINCT analytics_sessions.visitor_id) as visitors'), DB::raw('COUNT(*) as sessions'), DB::raw('COALESCE(SUM(analytics_sessions.page_views),0) as page_views'))
            ->groupBy(DB::raw("COALESCE(analytics_sessions.bot_name,'Other')"))->orderByDesc('page_views')->limit(12)->get()
            ->map(fn ($r) => ['name' => $r->name, 'type' => $r->type, 'visitors' => (int) $r->visitors, 'sessions' => (int) $r->sessions, 'page_views' => (int) $r->page_views])->all();
        $b['top_pages'] = $events(TrafficType::BOT)->where('type', 'page_view')->whereNotNull('url')
            ->select('url', DB::raw('COUNT(*) as views'), DB::raw('COUNT(DISTINCT visitor_id) as bots'))
            ->groupBy('url')->orderByDesc('views')->limit(10)->get()
            ->map(fn ($r) => ['url' => $r->url, 'views' => (int) $r->views, 'bots' => (int) $r->bots])->all();
        $b['by_country'] = DB::table('analytics_sessions')
            ->join('visitors', 'visitors.visitor_id', '=', 'analytics_sessions.visitor_id')
            ->where('analytics_sessions.traffic_type', TrafficType::BOT)->where('analytics_sessions.started_at', '>=', $since)
            ->select(DB::raw("COALESCE(visitors.country_code,'??') as country"), DB::raw('COUNT(DISTINCT analytics_sessions.visitor_id) as visitors'), DB::raw('COALESCE(SUM(analytics_sessions.page_views),0) as page_views'))
            ->groupBy(DB::raw("COALESCE(visitors.country_code,'??')"))->orderByDesc('page_views')->limit(10)->get()
            ->map(fn ($r) => ['country' => $r->country, 'visitors' => (int) $r->visitors, 'page_views' => (int) $r->page_views])->all();
        $b['by_user_agent'] = Visitor::where('traffic_type', TrafficType::BOT)->where('last_seen', '>=', $since)
            ->select('user_agent', DB::raw('COUNT(*) as visitors'), DB::raw('COALESCE(SUM(page_views),0) as page_views'))
            ->groupBy('user_agent')->orderByDesc('page_views')->limit(10)->get()
            ->map(fn ($r) => ['user_agent' => mb_substr((string) $r->user_agent, 0, 160), 'visitors' => (int) $r->visitors, 'page_views' => (int) $r->page_views])->all();
        unset($b);

        return $out;
    }

    /**
     * Merged recent-activity stream for the NOC. Pulls from existing tables
     * (orders, signups) AND storefront behaviour events, normalised + time-sorted.
     */
    public function activityFeed(int $limit = 40): array
    {
        $items = collect();

        // Orders (always available).
        DB::table('orders')->whereNull('parent_id')->whereNull('deleted_at')
            ->select('id', 'tracking_number', 'order_status', 'payment_status', 'paid_total', 'created_at')
            ->orderByDesc('id')->limit(25)->get()
            ->each(function ($o) use ($items) {
                $items->push([
                    'stream'   => 'orders',
                    'title'    => "Order #{$o->tracking_number}",
                    'subtitle' => str_replace('order-', '', (string) $o->order_status),
                    'value'    => (float) $o->paid_total,
                    'ref_id'   => $o->id,
                    'time'     => $o->created_at,
                ]);
                if (in_array($o->payment_status, ['payment-success', 'payment-wallet'], true)) {
                    $items->push([
                        'stream'   => 'payments',
                        'title'    => "Payment received",
                        'subtitle' => "#{$o->tracking_number}",
                        'value'    => (float) $o->paid_total,
                        'ref_id'   => $o->id,
                        'time'     => $o->created_at,
                    ]);
                }
            });

        // Signups (customers + vendors) — recent users with their primary role.
        User::with('roles:id,name')->orderByDesc('id')->limit(12)->get()
            ->each(function (User $u) use ($items) {
                $role = optional($u->roles->first())->name;
                $stream = $role === Permission::STORE_OWNER ? 'vendors' : 'customers';
                $items->push([
                    'stream'   => $stream,
                    'title'    => $stream === 'vendors' ? 'New vendor' : 'New customer',
                    'subtitle' => $u->name ?? $u->email,
                    'ref_id'   => $u->id,
                    'time'     => $u->created_at,
                ]);
            });

        // Storefront behaviour (high-signal events) — humans only; a crawler never "adds to cart".
        AnalyticsEvent::whereIn('type', AnalyticsEvent::KEY_EVENTS)
            ->where('traffic_type', TrafficType::HUMAN)
            ->orderByDesc('id')->limit(25)->get()
            ->each(function (AnalyticsEvent $e) use ($items) {
                $items->push([
                    'stream'   => 'behaviour',
                    'title'    => $this->humanEvent($e->type),
                    'subtitle' => $e->label ?? $e->url,
                    'value'    => $e->value !== null ? (float) $e->value : null,
                    'ref_id'   => null,
                    'time'     => $e->created_at,
                ]);
            });

        return $items
            ->filter(fn ($i) => !empty($i['time']))
            ->sortByDesc(fn ($i) => Carbon::parse($i['time'])->timestamp)
            ->take($limit)
            ->values()
            ->all();
    }

    private function humanEvent(string $type): string
    {
        return [
            'add_to_cart'       => 'Added to cart',
            'begin_checkout'    => 'Started checkout',
            'payment_initiated' => 'Started payment',
            'payment_success'   => 'Completed payment',
            'order_created'     => 'Placed order',
        ][$type] ?? ucfirst(str_replace('_', ' ', $type));
    }
}
