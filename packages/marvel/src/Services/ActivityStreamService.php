<?php

namespace Marvel\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Marvel\Enums\PaymentStatus;

/**
 * The NOC event stream + pulse metrics behind the Live Activity dashboard.
 *
 * Design rules (2-core prod): every query is window-bounded on an indexed
 * time column with a per-source LIMIT; the initial (no-since) payload is
 * server-cached; ?since= deltas are cheap indexed range scans. Each source
 * block is try/caught so schema drift on one table never darkens the feed.
 *
 * Severity vocabulary: critical | high | medium | low | info — the UI's
 * color system keys off these exact strings.
 */
class ActivityStreamService
{
    public function __construct(protected MetricsService $metrics)
    {
    }

    /**
     * Merged, severity-tagged event stream. $since = ISO timestamp from the
     * previous poll's server_time (delta mode); null = initial fill (cached 5s).
     */
    public function events(?string $since, int $limit = 80): array
    {
        if ($since === null) {
            return Cache::remember(
                'cc_events_initial',
                5,
                fn () => $this->buildEvents(null, $limit)
            );
        }
        return $this->buildEvents($since, $limit);
    }

    private function buildEvents(?string $since, int $limit): array
    {
        $serverTime = now();
        // Initial fill looks back a full day so the deck isn't empty on a
        // quiet store — per-source LIMITs keep the cost bounded either way.
        try {
            $from = $since ? Carbon::parse($since) : now()->subDay();
        } catch (\Throwable) {
            $from = now()->subDay();
        }
        // 24h floor: a stale tab waking up must not trigger an unbounded scan.
        $floor = now()->subDay();
        if ($from->lt($floor)) {
            $from = $floor;
        }

        $items = collect();
        $push = function (
            string $id,
            $time,
            string $stream,
            string $severity,
            ?string $module,
            string $title,
            ?string $subtitle,
            array $meta = []
        ) use ($items) {
            $items->push([
                'id' => $id,
                'time' => (string) $time,
                'stream' => $stream,
                'severity' => $severity,
                'module' => $module,
                'title' => $title,
                'subtitle' => $subtitle,
                'meta' => array_filter($meta, fn ($v) => $v !== null && $v !== ''),
            ]);
        };

        // ── Orders created ──────────────────────────────────────────────────
        try {
            DB::table('orders')->whereNull('parent_id')->whereNull('deleted_at')
                ->where('created_at', '>', $from)
                ->select('id', 'tracking_number', 'order_status', 'payment_status', 'paid_total', 'shipping_address', 'created_at')
                ->orderByDesc('id')->limit(40)->get()
                ->each(function ($o) use ($push) {
                    $sev = match ($o->payment_status) {
                        PaymentStatus::FAILED => 'high',
                        PaymentStatus::REFUNDED, PaymentStatus::REVERSAL => 'medium',
                        default => 'info',
                    };
                    $push("order:{$o->id}", $o->created_at, 'orders', $sev, 'orders',
                        "Order #{$o->tracking_number}",
                        str_replace('order-', '', (string) $o->order_status), [
                            'value' => (float) $o->paid_total,
                            'city' => $this->metrics->cityFromJson($o->shipping_address),
                            'ref_id' => $o->id,
                            'href' => "/orders/{$o->id}",
                        ]);
                });
        } catch (\Throwable) {
        }

        // ── Payment transitions (terminal states, by updated_at) ────────────
        try {
            DB::table('orders')->whereNull('parent_id')->whereNull('deleted_at')
                ->where('updated_at', '>', $from)
                ->whereIn('payment_status', [
                    PaymentStatus::SUCCESS, PaymentStatus::WALLET,
                    PaymentStatus::FAILED, PaymentStatus::REFUNDED,
                ])
                ->select('id', 'tracking_number', 'payment_status', 'paid_total', 'payment_gateway', 'updated_at')
                ->orderByDesc('updated_at')->limit(40)->get()
                ->each(function ($o) use ($push) {
                    [$sev, $title] = match ($o->payment_status) {
                        PaymentStatus::FAILED => ['high', 'Payment failed'],
                        PaymentStatus::REFUNDED => ['medium', 'Payment refunded'],
                        default => ['low', 'Payment received'],
                    };
                    $push("pay:{$o->id}:{$o->payment_status}", $o->updated_at, 'payments', $sev, 'payments',
                        $title, "#{$o->tracking_number}", [
                            'value' => (float) $o->paid_total,
                            'status' => $o->payment_gateway,
                            'ref_id' => $o->id,
                            'href' => "/orders/{$o->id}",
                        ]);
                });
        } catch (\Throwable) {
        }

        // ── Signups ─────────────────────────────────────────────────────────
        try {
            User::with('roles:id,name')->where('created_at', '>', $from)
                ->orderByDesc('id')->limit(15)->get()
                ->each(function (User $u) use ($push) {
                    $role = optional($u->roles->first())->name;
                    $vendor = $role === Permission::STORE_OWNER;
                    $push("user:{$u->id}", $u->created_at, $vendor ? 'vendors' : 'customers', 'info', 'users',
                        $vendor ? 'New vendor' : 'New customer',
                        $u->name ?? $u->email, [
                            'role' => $role,
                            'ref_id' => $u->id,
                            'href' => "/users/{$u->id}",
                        ]);
                });
        } catch (\Throwable) {
        }

        // ── Storefront behaviour (all tracked types) ────────────────────────
        try {
            DB::table('analytics_events')->where('created_at', '>', $from)
                ->select('id', 'type', 'label', 'url', 'value', 'ip', 'visitor_id', 'created_at')
                ->orderByDesc('id')->limit(40)->get()
                ->each(function ($e) use ($push) {
                    $sev = match ($e->type) {
                        'checkout_start', 'payment_complete' => 'low',
                        'non_serviceable_order', 'location_denied' => 'medium',
                        default => 'info',
                    };
                    $push("ae:{$e->id}", $e->created_at, 'behaviour', $sev, 'storefront',
                        ucfirst(str_replace('_', ' ', (string) $e->type)),
                        $e->label ?? $e->url, [
                            'value' => $e->value !== null ? (float) $e->value : null,
                            'ip' => $e->ip,
                        ]);
                });
        } catch (\Throwable) {
        }

        // ── API errors (request_logs ≥400, with cached geo) ─────────────────
        try {
            DB::table('request_logs')
                ->leftJoin('ip_locations', 'ip_locations.ip', '=', 'request_logs.ip')
                ->where('request_logs.created_at', '>', $from)
                ->where('request_logs.status', '>=', 400)
                ->select('request_logs.id', 'method', 'path', 'module', 'status', 'request_logs.ip',
                    'device', 'duration_ms', 'request_id', 'request_logs.created_at',
                    'ip_locations.city as geo_city', 'ip_locations.country as geo_country')
                ->orderByDesc('request_logs.id')->limit(40)->get()
                ->each(function ($l) use ($push) {
                    $sev = $l->status >= 500 ? 'high' : ($l->status === 429 ? 'medium' : 'low');
                    $push("log:{$l->id}", $l->created_at, 'api', $sev, $l->module,
                        "HTTP {$l->status} {$l->method}", $l->path, [
                            'log_id' => $l->id,
                            'status' => $l->status,
                            'ip' => $l->ip,
                            'city' => $l->geo_city,
                            'country' => $l->geo_country,
                            'device' => $l->device,
                            'duration_ms' => $l->duration_ms,
                            'request_id' => $l->request_id,
                        ]);
                });
        } catch (\Throwable) {
        }

        // ── Exceptions ──────────────────────────────────────────────────────
        try {
            DB::table('request_log_exceptions')->where('created_at', '>', $from)
                ->orderByDesc('id')->limit(25)->get()
                ->each(function ($e) use ($push) {
                    $push("ex:{$e->id}", $e->created_at, 'infra', 'critical', 'exceptions',
                        class_basename((string) $e->class), $e->message, [
                            'request_id' => $e->request_id,
                            'status' => $e->path,
                        ]);
                });
        } catch (\Throwable) {
        }

        // ── Integration failures + webhooks ─────────────────────────────────
        try {
            DB::table('integration_logs')->where('created_at', '>', $from)
                ->where(fn ($q) => $q->where('status', 'failed')->orWhere('action', 'webhook'))
                ->orderByDesc('id')->limit(25)->get()
                ->each(function ($l) use ($push) {
                    $failed = $l->status === 'failed';
                    // "Not configured" is a known state, not an outage — don't cry wolf.
                    $sev = !$failed ? 'info'
                        : (str_starts_with((string) $l->error_message, 'Not configured') ? 'low' : 'high');
                    $push("il:{$l->id}", $l->created_at, 'integrations', $sev, $l->provider_slug,
                        ucfirst(str_replace('_', ' ', (string) $l->action)) . ($failed ? ' failed' : ''),
                        $l->error_message ?? $l->provider_slug, [
                            'status' => $l->http_status,
                            'duration_ms' => $l->duration_ms,
                        ]);
                });
        } catch (\Throwable) {
        }

        // ── Logins (outbox is the only auth-success ledger; failed logins
        //    return HTTP 200 today, so they are deliberately not promised) ───
        try {
            DB::table('outbox')->where('event_name', 'identity.user_logged_in')
                ->where('occurred_at', '>', $from)
                ->orderByDesc('id')->limit(25)->get()
                ->each(function ($o) use ($push) {
                    $p = json_decode((string) $o->payload, true) ?: [];
                    $push("ob:{$o->id}", $o->occurred_at, 'auth', 'info', 'auth',
                        'User logged in', $p['email'] ?? $p['name'] ?? null, [
                            'user' => $p['name'] ?? null,
                            'ref_id' => $p['user_id'] ?? null,
                        ]);
                });
        } catch (\Throwable) {
        }

        // ── Delivery transitions (shipments current state, by updated_at) ───
        try {
            DB::table('shipments')->where('updated_at', '>', $from)
                ->select('id', 'order_id', 'status', 'courier_name', 'failure_reason', 'updated_at')
                ->orderByDesc('updated_at')->limit(25)->get()
                ->each(function ($s) use ($push) {
                    $sev = ($s->status === 'cancelled' || $s->failure_reason) ? 'high'
                        : ($s->status === 'delivered' ? 'low' : 'info');
                    $push("ship:{$s->id}:{$s->status}", $s->updated_at, 'delivery', $sev, 'shipping',
                        'Shipment ' . str_replace('_', ' ', (string) $s->status),
                        $s->failure_reason ?? ($s->courier_name ? "via {$s->courier_name}" : "order #{$s->order_id}"), [
                            'ref_id' => $s->order_id,
                            'href' => "/orders/{$s->order_id}",
                        ]);
                });
        } catch (\Throwable) {
        }

        return [
            'items' => $items
                ->filter(fn ($i) => !empty($i['time']))
                ->sortByDesc(fn ($i) => Carbon::parse($i['time'])->timestamp)
                ->take($limit)
                ->values()
                ->all(),
            'server_time' => $serverTime->toIso8601String(),
        ];
    }

    /**
     * Per-minute pulse for the ticker sparklines: 30 aligned buckets per
     * series (oldest → newest), headline totals, and rule-based insights.
     */
    public function pulse(): array
    {
        return Cache::remember('cc_pulse', 12, function () {
            $from = now()->startOfMinute()->subMinutes(29);
            $bucket = "DATE_FORMAT(created_at, '%Y-%m-%dT%H:%i:00')";

            // 30 aligned minute keys, zero-filled — sparklines consume the
            // values array directly, no client-side alignment needed.
            $keys = [];
            for ($i = 0; $i < 30; $i++) {
                $keys[] = $from->copy()->addMinutes($i)->format('Y-m-d\TH:i:00');
            }
            $fill = function (array $byMinute) use ($keys) {
                return array_map(fn ($k) => round((float) ($byMinute[$k] ?? 0), 2), $keys);
            };
            $grab = function (callable $query) use ($fill) {
                try {
                    return $fill($query());
                } catch (\Throwable) {
                    return array_fill(0, 30, 0.0);
                }
            };

            $orders = $grab(fn () => DB::table('orders')->whereNull('parent_id')->whereNull('deleted_at')
                ->where('created_at', '>=', $from)
                ->selectRaw("$bucket t, COUNT(*) v")->groupBy('t')->pluck('v', 't')->all());
            $revenue = $grab(fn () => DB::table('orders')->whereNull('parent_id')->whereNull('deleted_at')
                ->where('created_at', '>=', $from)
                ->selectRaw("$bucket t, COALESCE(SUM(paid_total),0) v")->groupBy('t')->pluck('v', 't')->all());
            $bucketUpd = str_replace('created_at', 'updated_at', $bucket);
            $paymentsOk = $grab(fn () => DB::table('orders')->whereNull('parent_id')->whereNull('deleted_at')
                ->where('updated_at', '>=', $from)
                ->whereIn('payment_status', [PaymentStatus::SUCCESS, PaymentStatus::WALLET])
                ->selectRaw("$bucketUpd t, COUNT(*) v")->groupBy('t')->pluck('v', 't')->all());
            $paymentsFailed = $grab(fn () => DB::table('orders')->whereNull('parent_id')->whereNull('deleted_at')
                ->where('updated_at', '>=', $from)
                ->where('payment_status', PaymentStatus::FAILED)
                ->selectRaw("$bucketUpd t, COUNT(*) v")->groupBy('t')->pluck('v', 't')->all());
            $signups = $grab(fn () => DB::table('users')->where('created_at', '>=', $from)
                ->selectRaw("$bucket t, COUNT(*) v")->groupBy('t')->pluck('v', 't')->all());
            $events = $grab(fn () => DB::table('analytics_events')->where('created_at', '>=', $from)
                ->selectRaw("$bucket t, COUNT(*) v")->groupBy('t')->pluck('v', 't')->all());
            $requests = $grab(fn () => DB::table('request_logs')->where('created_at', '>=', $from)
                ->selectRaw("$bucket t, COUNT(*) v")->groupBy('t')->pluck('v', 't')->all());
            $errors = $grab(fn () => DB::table('request_logs')->where('created_at', '>=', $from)
                ->selectRaw("$bucket t, SUM(status >= 400) v")->groupBy('t')->pluck('v', 't')->all());
            $latency = $grab(fn () => DB::table('request_logs')->where('created_at', '>=', $from)
                ->selectRaw("$bucket t, COALESCE(AVG(duration_ms),0) v")->groupBy('t')->pluck('v', 't')->all());

            $online = 0;
            try {
                $online = DB::table('visitors')->where('last_seen', '>=', now()->subMinutes(5))->count();
            } catch (\Throwable) {
            }

            $sum = fn (array $s, int $fromIdx, int $len) => array_sum(array_slice($s, $fromIdx, $len));
            $insights = [];
            $delta = function (string $label, array $s, float $threshold, int $minBase) use ($sum, &$insights) {
                $prev = $sum($s, 0, 15);
                $last = $sum($s, 15, 15);
                if ($prev >= $minBase && abs($last - $prev) / max(1, $prev) >= $threshold) {
                    $pct = round((($last - $prev) / max(1, $prev)) * 100);
                    $insights[] = [
                        'severity' => $pct < 0 && $label === 'Orders' ? 'medium' : ($pct > 0 && in_array($label, ['Errors'], true) ? 'high' : 'info'),
                        'text' => sprintf('%s %s %d%% vs previous 15m (%d → %d)', $label, $pct >= 0 ? 'up' : 'down', abs($pct), $prev, $last),
                    ];
                }
            };
            $delta('Orders', $orders, 0.3, 3);
            $delta('Errors', $errors, 0.5, 10);
            $delta('Signups', $signups, 2.0, 3);
            if (($f = $sum($paymentsFailed, 15, 15)) > 0) {
                $insights[] = ['severity' => 'high', 'text' => "{$f} failed payment" . ($f > 1 ? 's' : '') . ' in the last 15m'];
            }

            return [
                'series' => [
                    'orders' => $orders,
                    'revenue' => $revenue,
                    'payments_ok' => $paymentsOk,
                    'payments_failed' => $paymentsFailed,
                    'signups' => $signups,
                    'events' => $events,
                    'requests' => $requests,
                    'errors' => $errors,
                    'latency_ms' => $latency,
                ],
                'totals' => [
                    'orders_30m' => (int) $sum($orders, 0, 30),
                    'revenue_30m' => round($sum($revenue, 0, 30), 2),
                    'errors_30m' => (int) $sum($errors, 0, 30),
                    'requests_30m' => (int) $sum($requests, 0, 30),
                    'online' => $online,
                ],
                'insights' => $insights,
                'generated_at' => now()->toIso8601String(),
            ];
        });
    }
}
