<?php

namespace Marvel\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\AnalyticsEvent;
use Marvel\Database\Models\AnalyticsSession;
use Marvel\Database\Models\Visitor;
use Marvel\Services\Tracking\BotDetectionService;
use Marvel\Services\Tracking\TrafficType;

/**
 * Visitor / Live Activity NOC — public, fire-and-forget event ingest.
 *
 * Two legs feed it:
 *   POST /track        the storefront SDK (humans and anything else that runs JS)
 *   POST /track/crawl  the storefront's proxy.ts, for page requests whose UA
 *                      looks automated — the only way non-JS crawlers are seen.
 *
 * Every ping is classified human | bot | unknown (BotDetectionService) and
 * STORED with that label — bots are separated in the reports, never dropped.
 *
 * RUTHLESSLY fail-safe: any error is swallowed and a 204 is always returned,
 * so tracking can never affect a customer's browsing. Payload is capped and
 * whitelisted; retention lives in logs:prune, not here.
 */
class TrackingController extends CoreController
{
    public function __construct(private BotDetectionService $bots)
    {
    }

    /** The SDK leg. */
    public function ingest(Request $request)
    {
        return $this->handle($request, 'sdk');
    }

    /** The crawler leg (proxy.ts). UA arrives as the request header, ip + geo in the body. */
    public function ingestCrawl(Request $request)
    {
        return $this->handle($request, 'server');
    }

    private function handle(Request $request, string $source)
    {
        try {
            $this->process($request, $source);
        } catch (\Throwable $e) {
            // Tracking must NEVER surface an error to the storefront.
        }
        return response()->noContent(); // 204
    }

    protected function process(Request $request, string $source): void
    {
        $body = $this->body($request);
        if ($body === null) {
            return;
        }

        $ua = (string) $request->userAgent();
        $class = $this->bots->classify($ua);
        $type = $class['type'];
        $now = now();

        // The real client address: Cloudflare rewrites CF-Connecting-IP on every
        // proxied request, so it beats whatever TrustProxies resolves. The crawler
        // leg is a server-to-server call, so there the proxy passes the IP in the body.
        $ip = $source === 'server'
            ? $this->str($body['ip'] ?? null, 64)
            : ($this->str($request->header('CF-Connecting-IP'), 64) ?: $request->ip());

        if ($source === 'server') {
            // Crawlers carry no cookie: one visitor per (user-agent, ip).
            $visitorId = 'b:' . substr(sha1($ua . '|' . $ip), 0, 40);
            $sessionId = $visitorId;
        } else {
            $visitorId = $this->id($body['visitor_id'] ?? null);
            if ($visitorId === null) {
                return;
            }
            $sessionId = $this->id($body['session_id'] ?? null) ?? $visitorId;
        }

        $page = $this->path($body['page'] ?? null);
        $heartbeat = !empty($body['heartbeat']);
        // user_id is advisory (analytics only, never authorises) — and a bot never gets one.
        $userId = $type === TrafficType::HUMAN && is_numeric($body['user_id'] ?? null) ? (int) $body['user_id'] : null;
        $shoppingCity = $this->str($body['shopping_city'] ?? null, 120);

        if ($heartbeat) {
            $this->heartbeat($visitorId, $sessionId, $page, $userId, $now);
            return;
        }

        $events = $this->events($body['events'] ?? null, $page);
        $hasPageView = (bool) collect($events)->firstWhere('type', 'page_view');
        // Count a page view from exactly one leg: JS-running crawlers (Googlebot)
        // are counted by the crawl leg, humans whose UA tripped the proxy's coarse
        // regex by the SDK. "unknown" arrives from one leg only (see config/tracking.php).
        $countPv = $hasPageView
            && !($type === TrafficType::BOT && $source === 'sdk')
            && !($type === TrafficType::HUMAN && $source === 'server');

        // ── Visitor: one row, touched on every ping. ─────────────────────────
        $visitor = Visitor::firstOrNew(['visitor_id' => $visitorId]);
        $referrer = $this->str($body['referrer'] ?? null, 512);
        if (!$visitor->exists) {
            $visitor->first_seen = $now;
            $visitor->entry_page = $page;
            $visitor->referrer = $referrer;
        } elseif ($page && $visitor->current_page && $visitor->current_page !== $page) {
            $visitor->previous_page = $visitor->current_page;
        }

        [$device, $browser, $os] = $this->parseUa($ua);
        $geo = $this->geo($request, $body, $source, $ip, $visitor);

        $visitor->fill([
            'user_id'      => $type === TrafficType::HUMAN ? ($userId ?: $visitor->user_id) : null,
            'session_id'   => $sessionId,
            'ip'           => $ip,
            'user_agent'   => $this->str($ua, 512),
            'device'       => $device,
            'browser'      => $browser,
            'os'           => $os,
            'traffic_type' => $type,
            'bot_name'     => $class['name'],
            'bot_type'     => $class['bot_type'],
            'country_code' => $geo['country_code'] ?? $visitor->country_code,
            'country'      => $geo['country'] ?? $visitor->country ?? $geo['country_code'] ?? null,
            'state'        => $geo['state'] ?? $visitor->state,
            'city'         => $geo['city'] ?? $visitor->city,
            'shopping_city' => $shoppingCity ?: $visitor->shopping_city,
            'current_page' => $page ?: $visitor->current_page,
            'page_views'   => (int) ($visitor->page_views ?? 0) + ($countPv ? 1 : 0),
            'last_seen'    => $now,
        ]);
        // First-touch attribution: written once, never overwritten.
        $utm = $this->utm($body['utm'] ?? null);
        foreach ($utm as $k => $v) {
            if ($visitor->{$k} === null) {
                $visitor->{$k} = $v;
            }
        }
        $visitor->save();

        // ── Session: latest row for this id, or a new one after 30 min of silence. ──
        $session = $this->resolveSession($sessionId, $visitorId, $now);
        if (!$session->exists) {
            $session->fill([
                'started_at'   => $now,
                'landing_page' => $page,
                'referrer'     => $referrer,
            ] + ($utm ?: $this->visitorUtm($visitor)));
        }
        $session->fill([
            'traffic_type' => $type,
            'bot_name'     => $class['name'],
            'user_id'      => $userId ?: $session->user_id,
            'last_seen_at' => $now,
            'exit_page'    => $page ?: $session->exit_page,
            'page_views'   => (int) ($session->page_views ?? 0) + ($countPv ? 1 : 0),
        ]);
        $engaging = (bool) collect($events)->first(fn ($e) => in_array($e['type'], ['product_view', 'add_to_cart', 'begin_checkout'], true));
        $session->engaged = $this->engaged($session, $now, $engaging);
        $session->save();
        if ($session->engaged && !$visitor->engaged) {
            $visitor->forceFill(['engaged' => true])->save();
        }

        // ── Events (bulk, capped, whitelisted). ─────────────────────────────
        if ($events) {
            AnalyticsEvent::insert(array_map(fn (array $e) => $e + [
                'visitor_id'   => $visitorId,
                'session_id'   => $sessionId,
                'user_id'      => $userId,
                'traffic_type' => $type,
                'ip'           => $ip,
                'created_at'   => $now,
            ], $events));
        }
    }

    /** Exactly two UPDATEs: the visitor's last_seen and the session's last_seen_at. */
    private function heartbeat(string $visitorId, string $sessionId, ?string $page, ?int $userId, Carbon $now): void
    {
        $touch = ['last_seen' => $now];
        if ($page) {
            $touch['current_page'] = $page;
        }
        Visitor::where('visitor_id', $visitorId)->update($touch);

        $session = AnalyticsSession::where('session_id', $sessionId)->orderByDesc('id')->first();
        if (!$session || $this->stale($session, $now)) {
            return; // nothing to extend — the next page_view opens a new session
        }
        $session->fill([
            'last_seen_at' => $now,
            'exit_page'    => $page ?: $session->exit_page,
            'user_id'      => $userId ?: $session->user_id,
        ]);
        $session->engaged = $this->engaged($session, $now, false);
        $session->save();
    }

    private function resolveSession(string $sessionId, string $visitorId, Carbon $now): AnalyticsSession
    {
        $session = AnalyticsSession::where('session_id', $sessionId)->orderByDesc('id')->first();
        if ($session && !$this->stale($session, $now)) {
            return $session;
        }
        // Same id, new row: old cached bundles and crawlers never rotate their id.
        return new AnalyticsSession(['session_id' => $sessionId, 'visitor_id' => $visitorId, 'page_views' => 0, 'engaged' => false]);
    }

    private function stale(AnalyticsSession $session, Carbon $now): bool
    {
        $timeout = (int) config('tracking.session_timeout_min', 30);
        return !$session->last_seen_at || $session->last_seen_at->lt($now->copy()->subMinutes($timeout));
    }

    /** Human only; once true, stays true. */
    private function engaged(AnalyticsSession $session, Carbon $now, bool $engagingEvent): bool
    {
        if ($session->engaged) {
            return true;
        }
        if ($session->traffic_type !== TrafficType::HUMAN) {
            return false;
        }
        $onSite = $session->started_at ? $session->started_at->diffInSeconds($now) : 0;
        return $engagingEvent
            || (int) $session->page_views >= 2
            || $onSite >= (int) config('tracking.engaged_after_sec', 30);
    }

    /**
     * Coarse geo, best source first: the crawl leg passes what the storefront's
     * edge saw; the SDK leg reads Cloudflare's headers (cf-ipcity needs the free
     * "Add visitor location headers" transform; cf-ipcountry is always there),
     * then Vercel's on staging; last, the ip_locations table — looked up only
     * while the visitor still has no country, so heartbeats never pay for it.
     *
     * @return array{country_code: ?string, country: ?string, state: ?string, city: ?string}
     */
    private function geo(Request $request, array $body, string $source, ?string $ip, Visitor $visitor): array
    {
        $g = ['country_code' => null, 'country' => null, 'state' => null, 'city' => null];
        if ($source === 'server') {
            $g['country_code'] = $this->code($body['country_code'] ?? null);
            $g['state'] = $this->str($body['state'] ?? null, 80);
            $g['city'] = $this->str($body['city'] ?? null, 120);
        } else {
            $h = fn (string $cf, string $vercel) => $request->header($cf) ?: $request->header($vercel);
            $g['country_code'] = $this->code($h('cf-ipcountry', 'x-vercel-ip-country'));
            $g['state'] = $this->str($h('cf-region-code', 'x-vercel-ip-country-region'), 80);
            $g['city'] = $this->str(urldecode((string) $h('cf-ipcity', 'x-vercel-ip-city')), 120);
        }

        if ($g['country_code'] === null && $ip && $visitor->country_code === null && Schema::hasTable('ip_locations')) {
            $row = DB::table('ip_locations')->where('ip', $ip)->first();
            if ($row && !empty($row->country_code)) {
                $g = [
                    'country_code' => $this->code($row->country_code),
                    'country'      => $this->str($row->country, 80),
                    'state'        => $this->str($row->region, 80),
                    'city'         => $this->str($row->city, 120),
                ];
            }
        }
        return $g;
    }

    /** Cloudflare sends XX (unknown) / T1 (Tor) — neither is a country. */
    private function code($value): ?string
    {
        $value = strtoupper((string) $this->str($value, 2));
        return preg_match('/^[A-Z]{2}$/', $value) && !in_array($value, ['XX', 'T1'], true) ? $value : null;
    }

    /** ≤ max_body_bytes, JSON whether the content type says so (sendBeacon) or not (fetch text/plain). */
    private function body(Request $request): ?array
    {
        $raw = (string) $request->getContent();
        if ($raw === '' || strlen($raw) > (int) config('tracking.max_body_bytes', 16384)) {
            return null;
        }
        $data = $request->isJson() ? $request->json()->all() : json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Whitelisted, capped, URL-sanitised event rows (without the per-ping columns).
     *
     * @return array<int, array{type: string, url: ?string, referrer: ?string, label: ?string, value: ?float, meta: ?string}>
     */
    private function events($events, ?string $page): array
    {
        if (!is_array($events)) {
            return [];
        }
        $rows = [];
        foreach (array_slice(array_values($events), 0, (int) config('tracking.max_events', 20)) as $e) {
            if (!is_array($e)) {
                continue;
            }
            $type = AnalyticsEvent::canonicalType($e['type'] ?? null);
            if ($type === null) {
                continue;
            }
            $rows[] = [
                'type'     => $type,
                'url'      => $this->path($e['url'] ?? null) ?? $page,
                'referrer' => $this->str($e['referrer'] ?? null, 512),
                'label'    => $this->str($e['label'] ?? null, 255),
                'value'    => isset($e['value']) && is_numeric($e['value']) ? (float) $e['value'] : null,
                'meta'     => $this->meta($e['meta'] ?? null),
            ];
        }
        return $rows;
    }

    /** Only known keys, scalar values, each capped — then encoded whole. Never truncate JSON. */
    private function meta($meta): ?string
    {
        if (!is_array($meta)) {
            return null;
        }
        $clean = [];
        foreach (AnalyticsEvent::META_KEYS as $key) {
            if (!array_key_exists($key, $meta) || !is_scalar($meta[$key])) {
                continue;
            }
            $clean[$key] = is_string($meta[$key]) ? mb_substr(trim($meta[$key]), 0, 255) : $meta[$key];
        }
        return $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE) : null;
    }

    /** @return array<string, string> only the utm_* keys that were sent */
    private function utm($utm): array
    {
        if (!is_array($utm)) {
            return [];
        }
        $out = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $k) {
            $v = $this->str($utm[$k] ?? null, 120);
            if ($v !== null) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private function visitorUtm(Visitor $visitor): array
    {
        $out = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $k) {
            if ($visitor->{$k} !== null) {
                $out[$k] = $visitor->{$k};
            }
        }
        return $out;
    }

    /** Path only — the query string is where reset tokens and search terms live. */
    private function path($url): ?string
    {
        $url = $this->str($url, 2048);
        if ($url === null) {
            return null;
        }
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $path = preg_replace('/[?#].*$/', '', $path) ?? '';
        return $path === '' ? null : substr($path, 0, 512);
    }

    /** Visitor/session ids are opaque tokens the SDK made: letters, digits, : . _ - */
    private function id($value): ?string
    {
        $value = $this->str($value, 64);
        return $value !== null && preg_match('/^[\w:.-]{8,64}$/', $value) ? $value : null;
    }

    /** Trim + cap a string; null/empty → null. */
    private function str($value, int $max): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** Lightweight UA → [device, browser, os]. */
    private function parseUa(string $ua): array
    {
        $device = 'desktop';
        if (preg_match('/iPad|Tablet/i', $ua)) {
            $device = 'tablet';
        } elseif (preg_match('/Mobi|Android.*Mobile|iPhone|iPod/i', $ua)) {
            $device = 'mobile';
        }

        $browser = 'Other';
        if (preg_match('/Edg/i', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('/OPR|Opera/i', $ua)) {
            $browser = 'Opera';
        } elseif (preg_match('/Chrome|CriOS/i', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Firefox|FxiOS/i', $ua)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Safari/i', $ua)) {
            $browser = 'Safari';
        }

        $os = 'Other';
        if (preg_match('/Windows/i', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/iPhone|iPad|iOS/i', $ua)) {
            $os = 'iOS';
        } elseif (preg_match('/Mac OS X|Macintosh/i', $ua)) {
            $os = 'macOS';
        } elseif (preg_match('/Android/i', $ua)) {
            $os = 'Android';
        } elseif (preg_match('/Linux/i', $ua)) {
            $os = 'Linux';
        }

        return [$device, $browser, $os];
    }
}
