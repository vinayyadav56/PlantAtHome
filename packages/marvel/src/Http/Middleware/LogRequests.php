<?php

namespace Marvel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\RequestLog;
use Marvel\Database\Models\RequestLogSetting;

/**
 * Records every API request in & out (method, path, payload, response, status,
 * error, duration) for the admin "Request Logs" viewer. Terminable so it runs
 * after the response is sent. Sensitive fields are redacted, bodies truncated,
 * GET reads skipped by default, and old rows pruned per the retention setting.
 */
class LogRequests
{
    private const MAX_BODY = 8000;
    private const REDACT = [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'token', 'secret', 'authorization', 'api_key', 'key_secret', 'client_secret',
        'cvv', 'otp', 'card', 'card_number', 'razorpay_signature',
    ];
    private const SKIP_CONTAINS = ['request-logs', 'admin-tasks', '/health'];

    public function handle(Request $request, Closure $next)
    {
        $request->attributes->set('_log_start', microtime(true));
        return $next($request);
    }

    public function terminate(Request $request, $response): void
    {
        try {
            $settings = $this->settings();
            if (!$settings['enabled']) {
                return;
            }
            $path = '/' . ltrim($request->path(), '/');
            foreach (self::SKIP_CONTAINS as $skip) {
                if (str_contains($path, $skip)) {
                    return;
                }
            }
            $method = $request->method();
            if ($method === 'GET' && !$settings['log_get']) {
                return;
            }

            $start = (float) $request->attributes->get('_log_start', microtime(true));
            $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : null;
            $content = method_exists($response, 'getContent') ? (string) $response->getContent() : '';

            RequestLog::create([
                'method' => $method,
                'path' => mb_substr($path, 0, 512),
                'status' => $status,
                'user_id' => optional($request->user())->id,
                'ip' => $request->ip(),
                'payload' => $this->trunc($this->redact($request->all())),
                'response' => $this->trunc($content),
                'error' => $status >= 400 ? $this->trunc($content) : null,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'created_at' => now(),
            ]);

            // Opportunistic retention prune (~2% of logged requests).
            if (random_int(1, 50) === 1) {
                RequestLog::where('created_at', '<', now()->subDays(max(1, $settings['retention_days'])))->limit(2000)->delete();
            }
        } catch (\Throwable $e) {
            // never let logging break a request
        }
    }

    private function settings(): array
    {
        return Cache::remember('request_log_settings', 30, function () {
            $s = RequestLogSetting::first();
            return [
                'enabled' => $s ? (bool) $s->enabled : false,
                'retention_days' => $s ? (int) $s->retention_days : 7,
                'log_get' => $s ? (bool) $s->log_get : false,
            ];
        });
    }

    private function redact($data)
    {
        if (!is_array($data)) {
            return $data;
        }
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), self::REDACT, true)) {
                $data[$k] = '***redacted***';
            } elseif ($v instanceof \Illuminate\Http\UploadedFile) {
                $data[$k] = '[file:' . $v->getClientOriginalName() . ']';
            } elseif (is_array($v)) {
                $data[$k] = $this->redact($v);
            }
        }
        return $data;
    }

    private function trunc($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($s === false) {
            return null;
        }
        return mb_strlen($s) > self::MAX_BODY ? mb_substr($s, 0, self::MAX_BODY) . '…[truncated]' : $s;
    }
}
