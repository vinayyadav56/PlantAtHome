<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Marvel\Database\Models\AnalyticsEvent;
use Marvel\Database\Models\Visitor;

/**
 * Visitor / Live Activity NOC (Phase 3) — public, fire-and-forget event ingest
 * from the storefront. It is RUTHLESSLY fail-safe: any error is swallowed and a
 * 204 is always returned, so tracking can never affect a customer's browsing.
 * Rate-limited at the route; payload is capped + truncated; old rows are pruned
 * opportunistically so the table never runs away.
 */
class TrackingController extends CoreController
{
    private const MAX_EVENTS = 20;
    private const EVENT_RETENTION_DAYS = 14;
    private const VISITOR_RETENTION_DAYS = 30;

    public function ingest(Request $request)
    {
        try {
            $this->process($request);
        } catch (\Throwable $e) {
            // Tracking must NEVER surface an error to the storefront.
        }
        return response()->noContent(); // 204
    }

    protected function process(Request $request): void
    {
        $visitorId = $this->str($request->input('visitor_id'), 64);
        if ($visitorId === null || $visitorId === '') {
            return;
        }

        $events = $request->input('events', []);
        if (!is_array($events)) {
            $events = [];
        }

        $now = now();
        $page = $this->str($request->input('page'), 512);
        $sessionId = $this->str($request->input('session_id'), 64);
        $userId = $request->input('user_id'); // advisory (analytics only — never authorises)
        $userId = is_numeric($userId) ? (int) $userId : null;
        $ip = $request->ip();
        $ua = (string) $request->userAgent();
        [$device, $browser, $os] = $this->parseUa($ua);

        // ── Upsert the visitor (one row, touched). ──────────────────────────
        $visitor = Visitor::firstOrNew(['visitor_id' => $visitorId]);
        $hasPageView = collect($events)->contains(fn ($e) => ($e['type'] ?? '') === 'page_view');

        if (!$visitor->exists) {
            $visitor->first_seen = $now;
            $visitor->entry_page = $page;
            $visitor->referrer = $this->str($request->input('referrer'), 512);
        } elseif ($page && $visitor->current_page && $visitor->current_page !== $page) {
            $visitor->previous_page = $visitor->current_page;
        }

        $visitor->fill([
            'user_id'      => $userId ?: $visitor->user_id,
            'session_id'   => $sessionId ?: $visitor->session_id,
            'ip'           => $ip,
            'user_agent'   => $this->str($ua, 512),
            'device'       => $device,
            'browser'      => $browser,
            'os'           => $os,
            'city'         => $this->str($request->input('city'), 120) ?: $visitor->city,
            'country'      => $this->str($request->input('country'), 80) ?: $visitor->country,
            'current_page' => $page ?: $visitor->current_page,
            'page_views'   => (int) ($visitor->page_views ?? 0) + ($hasPageView ? 1 : 0),
            'last_seen'    => $now,
        ]);
        $visitor->save();

        // ── Insert the events (bulk, capped). ───────────────────────────────
        $rows = [];
        foreach (array_slice($events, 0, self::MAX_EVENTS) as $e) {
            $type = $this->str($e['type'] ?? '', 40);
            if (!$type) {
                continue;
            }
            $meta = $e['meta'] ?? null;
            $rows[] = [
                'visitor_id' => $visitorId,
                'session_id' => $sessionId,
                'user_id'    => $userId,
                'type'       => $type,
                'url'        => $this->str($e['url'] ?? $page, 512),
                'referrer'   => $this->str($e['referrer'] ?? null, 512),
                'label'      => $this->str($e['label'] ?? null, 255),
                'value'      => isset($e['value']) && is_numeric($e['value']) ? (float) $e['value'] : null,
                'meta'       => $meta ? substr((string) json_encode($meta), 0, 2000) : null,
                'ip'         => $ip,
                'created_at' => $now,
            ];
        }
        if ($rows) {
            AnalyticsEvent::insert($rows);
        }

        // ── Opportunistic prune (~1% of requests). ──────────────────────────
        if (mt_rand(1, 100) === 1) {
            AnalyticsEvent::where('created_at', '<', now()->subDays(self::EVENT_RETENTION_DAYS))->limit(5000)->delete();
            Visitor::where('last_seen', '<', now()->subDays(self::VISITOR_RETENTION_DAYS))->limit(2000)->delete();
        }
    }

    /** Trim + cap a string; null/empty → null. */
    private function str($value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : substr($value, 0, $max);
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
