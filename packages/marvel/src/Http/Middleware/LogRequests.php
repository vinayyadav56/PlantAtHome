<?php

namespace Marvel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Marvel\Database\Models\RequestLog;
use Marvel\Database\Models\RequestLogSetting;

/**
 * Records API requests (method, path, module, device, payload, status, error,
 * duration) for the admin "Request Logs" observability center. Terminable so it
 * runs after the response is flushed — the client never waits on logging.
 *
 * Deliberate shape for a 2-core box:
 *   - ONE synchronous slim insert. A queued write on the `database` queue driver
 *     would be three writes (job insert + delete + log insert) plus worker
 *     contention — strictly worse here.
 *   - No geo lookups, no UA-parser library, no external HTTP in the request
 *     path. Device is a six-substring classification; geo enrichment happens in
 *     a scheduled command over distinct IPs.
 *   - Success responses store NO body by default (store_response_bodies opts
 *     in); error responses always keep their redacted body — that is the row an
 *     operator actually opens.
 *
 * Retention is handled by the scheduled `logs:prune` command. The old
 * opportunistic 1-in-50 delete that lived here silently stopped pruning the
 * moment logging was disabled — the most likely reaction to an oversized table
 * froze the table at its peak size forever.
 */
class LogRequests
{
    private const MAX_BODY = 8000;
    private const REDACT = [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'token', 'secret', 'authorization', 'api_key', 'key_secret', 'client_secret',
        'cvv', 'otp', 'card', 'card_number', 'razorpay_signature',
        // response-side bearer secrets (auth/register/social/otp login mint these)
        'plaintexttoken', 'access_token', 'refresh_token', 'auth_token',
        // courier partner secret field names (POST /courier-settings)
        'api_token', 'webhook_token', 'callback_token', 'callback_key',
    ];
    // Whole nested secret bags — redact the entire value (covers any current/future field name).
    private const REDACT_SUBTREE = ['credentials', 'secrets'];
    private const SKIP_CONTAINS = ['request-logs', 'admin-tasks', '/health'];

    // Headers worth keeping, allowlisted — never the Authorization/Cookie family.
    private const HEADER_ALLOWLIST = ['referer', 'origin', 'accept-language', 'content-type'];

    public function handle(Request $request, Closure $next)
    {
        $request->attributes->set('_log_start', microtime(true));
        // ULID up front so the exception handler can correlate its rows with this
        // request even when the request row is written later (terminate).
        $request->attributes->set('_log_id', (string) Str::ulid());
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
            $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : null;

            // GETs are skipped by default for volume — but a FAILED read is signal,
            // not noise. Before this rule, every 404/500 on a GET was invisible.
            if ($method === 'GET' && !$settings['log_get'] && ($status === null || $status < 400)) {
                return;
            }

            $start = (float) $request->attributes->get('_log_start', microtime(true));
            $content = method_exists($response, 'getContent') ? (string) $response->getContent() : '';
            $isError = $status !== null && $status >= 400;

            RequestLog::create([
                'request_id' => $request->attributes->get('_log_id'),
                'method' => $method,
                'path' => mb_substr($path, 0, 512),
                'module' => $this->moduleOf($path),
                'status' => $status,
                'user_id' => optional($request->user())->id,
                'ip' => $request->ip(),
                'ua' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
                'device' => $this->classifyDevice((string) $request->userAgent()),
                'headers' => $this->pickHeaders($request),
                'payload' => $this->trunc($this->redact($request->all())),
                // Success bodies are the bulk of the table and nobody reads them;
                // errors keep theirs below. store_response_bodies opts back in.
                'response' => $settings['store_response_bodies'] && !$isError
                    ? $this->trunc($this->redactBody($content))
                    : null,
                // SECURITY: scrub the RESPONSE body too — /token, /register, social + OTP login
                // return a live Sanctum bearer as a top-level `token`, which was previously
                // persisted in plaintext (working credentials at rest).
                'error' => $isError ? $this->trunc($this->redactBody($content)) : null,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // never let logging break a request
        }
    }

    /**
     * The surface a request belongs to: the first path segment after /api or
     * /api/v1. This is the "which module" filter — cheap enough to compute
     * inline, and indexed so the admin can slice by it.
     */
    private function moduleOf(string $path): ?string
    {
        if (preg_match('#^/api/(?:v1/)?([a-z0-9_-]+)#i', $path, $m)) {
            return mb_substr(strtolower($m[1]), 0, 32);
        }
        return null;
    }

    /**
     * Four-way device classification from cheap substring checks. Deliberately
     * NOT a UA-parser library: browser-version granularity answers no question
     * an admin triaging logs actually asks, and the raw UA is stored anyway.
     */
    private function classifyDevice(string $ua): ?string
    {
        if ($ua === '') {
            return 'none';
        }
        foreach (['bot', 'crawl', 'spider', 'python', 'curl', 'wget', 'httpclient'] as $b) {
            if (stripos($ua, $b) !== false) {
                return 'bot';
            }
        }
        foreach (['mobile', 'android', 'iphone', 'ipad'] as $m) {
            if (stripos($ua, $m) !== false) {
                return 'mobile';
            }
        }
        return 'desktop';
    }

    /** JSON of the allowlisted headers only — never Authorization/Cookie. */
    private function pickHeaders(Request $request): ?string
    {
        $out = [];
        foreach (self::HEADER_ALLOWLIST as $h) {
            $v = $request->header($h);
            if ($v !== null && $v !== '') {
                $out[$h] = mb_substr((string) $v, 0, 255);
            }
        }
        return $out === [] ? null : json_encode($out, JSON_UNESCAPED_SLASHES);
    }

    private function settings(): array
    {
        return Cache::remember('request_log_settings', 30, function () {
            $s = RequestLogSetting::first();
            return [
                'enabled' => $s ? (bool) $s->enabled : false,
                'retention_days' => $s ? (int) $s->retention_days : 30,
                'log_get' => $s ? (bool) $s->log_get : false,
                'store_response_bodies' => $s ? (bool) ($s->store_response_bodies ?? false) : false,
            ];
        });
    }

    private function redact($data)
    {
        if (!is_array($data)) {
            return $data;
        }
        foreach ($data as $k => $v) {
            $lk = is_string($k) ? strtolower($k) : '';
            if ($lk !== '' && in_array($lk, self::REDACT, true)) {
                $data[$k] = '***redacted***';
            } elseif ($lk !== '' && in_array($lk, self::REDACT_SUBTREE, true)) {
                // Redact the WHOLE nested bag (e.g. courier partner credentials) so no secret
                // field name (api_token/webhook_token/callback_*/url/...) can slip through.
                $data[$k] = '***redacted***';
            } elseif ($v instanceof \Illuminate\Http\UploadedFile) {
                $data[$k] = '[file:' . $v->getClientOriginalName() . ']';
            } elseif (is_array($v)) {
                $data[$k] = $this->redact($v);
            }
        }
        return $data;
    }

    /** Redact secret keys inside a JSON response body before it is persisted to request_logs. */
    private function redactBody($content)
    {
        if (!is_string($content) || $content === '') {
            return $content;
        }
        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return json_encode($this->redact($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $content;
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
