<?php

namespace Marvel\Services\Courier;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Borzo (ex-WeFast) Business API client — on-demand intra-city delivery. Auth is the
 * X-DV-Auth-Token header (env-only; NEVER logged). Borzo signals success with an HTTP 2xx AND
 * `is_successful: true` in the body, so ok() requires both; field/param errors come back in
 * `errors` / `parameter_errors` / `warnings`. Every call returns the same structured shape the
 * rest of the courier layer uses — ['ok','status','data','error'] — and never throws for an
 * API-level failure.
 *
 * Endpoints (Business API v1.8):
 *   POST calculate-order   price/validate before booking
 *   POST create-order      place the delivery (>=2 points: [0]=pickup, [1..]=drop)
 *   POST cancel-order      {order_id}
 *   GET  orders?order_id=  order + delivery status (tracking)
 *   GET  courier?order_id= courier contact + live location
 *   GET  client            profile + allowed payment methods (cash => COD available)
 */
class BorzoClient
{
    private string $baseUrl;
    private ?string $token;

    public function __construct()
    {
        // Trailing slash trimmed; base already includes /api/business/<version>.
        $this->baseUrl = rtrim((string) config('services.borzo.base_url'), '/');
        $this->token = config('services.borzo.token');
    }

    public function configured(): bool
    {
        return !empty($this->baseUrl) && !empty($this->token);
    }

    public function calculateOrder(array $payload): array
    {
        return $this->request('post', '/calculate-order', $payload);
    }

    public function createOrder(array $payload): array
    {
        return $this->request('post', '/create-order', $payload);
    }

    public function cancelOrder($orderId): array
    {
        return $this->request('post', '/cancel-order', ['order_id' => (int) $orderId]);
    }

    public function getOrder($orderId): array
    {
        return $this->request('get', '/orders', ['order_id' => (int) $orderId]);
    }

    public function getCourier($orderId): array
    {
        return $this->request('get', '/courier', ['order_id' => (int) $orderId]);
    }

    public function client(): array
    {
        return $this->request('get', '/client', []);
    }

    // ── internals ─────────────────────────────────────────────────

    private function request(string $method, string $path, array $params): array
    {
        if (!$this->configured()) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Borzo is not configured (token/base_url missing).'];
        }

        try {
            $http = Http::timeout(25)
                ->withHeaders(['X-DV-Auth-Token' => $this->token]) // token header — never logged
                ->acceptJson();
            $url = $this->baseUrl . $path;
            $resp = $method === 'get' ? $http->get($url, $params) : $http->asJson()->post($url, $params);
        } catch (\Throwable $e) {
            Log::warning('Borzo request failed', ['path' => $path, 'error' => $e->getMessage()]); // no token/headers
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Network error contacting Borzo.'];
        }

        $body = $resp->json();
        $apiOk = $resp->successful() && (bool) data_get($body, 'is_successful', false);

        return [
            'ok'     => $apiOk,
            'status' => $resp->status(),
            'data'   => $body,
            'error'  => $apiOk ? null : $this->extractError($resp->status(), $body),
        ];
    }

    /** Human-readable error from Borzo's errors / parameter_errors / warnings (never leaks the token). */
    private function extractError(int $status, $body): string
    {
        // Prefer the specific parameter errors (e.g. "cod_agreement_required") over the generic
        // "invalid_parameters" envelope, so operators see the actionable reason.
        if ($pe = data_get($body, 'parameter_errors')) {
            $flat = $this->flattenParamErrors($pe);
            if ($flat !== '') {
                return 'Borzo: ' . $flat;
            }
        }
        foreach (['errors', 'warnings'] as $key) {
            $errs = data_get($body, $key);
            if (is_array($errs) && $errs) {
                return 'Borzo: ' . implode(', ', array_map(fn ($e) => is_string($e) ? $e : json_encode($e), $errs));
            }
        }
        return 'Borzo API error (' . $status . ').';
    }

    /** Collect the leaf error codes from Borzo's nested parameter_errors structure. */
    private function flattenParamErrors($node): string
    {
        $out = [];
        $walk = function ($n) use (&$walk, &$out) {
            if (is_array($n)) {
                foreach ($n as $v) {
                    $walk($v);
                }
            } elseif (is_string($n) && $n !== '') {
                $out[] = $n;
            }
        };
        $walk($node);
        return implode(', ', array_unique($out));
    }
}
