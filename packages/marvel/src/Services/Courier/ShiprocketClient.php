<?php

namespace Marvel\Services\Courier;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Shiprocket REST client. Auth token is cached ~9 days (Shiprocket tokens live ~10) and
 * transparently refreshed once on a 401/403. The token is never logged. Every call returns
 * a structured ['ok','status','data','error'] so the domain layer (CourierService) can react
 * to partial failures (e.g. order created but AWB allocation failed) instead of catching.
 */
class ShiprocketClient implements CourierProviderInterface
{
    private const TOKEN_CACHE_KEY = 'shiprocket_auth_token';

    private string $baseUrl;
    private ?string $email;
    private ?string $password;
    private ?string $apiToken;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.shiprocket.base_url', 'https://apiv2.shiprocket.in'), '/');
        $this->email = config('services.shiprocket.email');
        $this->password = config('services.shiprocket.password');
        $this->apiToken = config('services.shiprocket.api_token');
    }

    /** Cancel one or more provider orders (POST /orders/cancel). Used by the cancel lane. */
    public function cancelOrder(array $providerOrderIds): array
    {
        return $this->request('post', '/v1/external/orders/cancel', ['ids' => array_values($providerOrderIds)]);
    }

    public function serviceability(string $pickupPin, string $dropPin, int $weightGrams, bool $cod): array
    {
        return $this->request('get', '/v1/external/courier/serviceability/', [
            'pickup_postcode'   => $pickupPin,
            'delivery_postcode' => $dropPin,
            'weight'            => max(0.1, round($weightGrams / 1000, 3)), // kg
            'cod'               => $cod ? 1 : 0,
        ]);
    }

    public function createOrder(array $payload): array
    {
        return $this->request('post', '/v1/external/orders/create/adhoc', $payload);
    }

    public function assignAwb(string $providerShipmentId, ?int $courierId = null): array
    {
        $body = ['shipment_id' => $providerShipmentId];
        if ($courierId) {
            $body['courier_id'] = $courierId;
        }
        return $this->request('post', '/v1/external/courier/assign/awb', $body);
    }

    public function generateLabel(array $providerShipmentIds): array
    {
        return $this->request('post', '/v1/external/courier/generate/label', ['shipment_id' => array_values($providerShipmentIds)]);
    }

    public function schedulePickup(array $providerShipmentIds): array
    {
        return $this->request('post', '/v1/external/courier/generate/pickup', ['shipment_id' => array_values($providerShipmentIds)]);
    }

    public function track(string $awbOrShipmentId): array
    {
        return $this->request('get', '/v1/external/courier/track/awb/' . rawurlencode($awbOrShipmentId), []);
    }

    public function addPickupLocation(array $address): array
    {
        return $this->request('post', '/v1/external/settings/company/addpickup', $address);
    }

    public function listPickupLocations(): array
    {
        return $this->request('get', '/v1/external/settings/company/pickup', []);
    }

    // ── internals ─────────────────────────────────────────────────

    /** Authenticated request with one transparent token refresh on 401/403. */
    private function request(string $method, string $path, array $params, bool $retry = true): array
    {
        $token = $this->token();
        if (!$token) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Shiprocket auth failed (check credentials).'];
        }

        try {
            $http = Http::timeout(20)->withToken($token)->acceptJson();
            $resp = $method === 'get' ? $http->get($this->baseUrl . $path, $params) : $http->post($this->baseUrl . $path, $params);
        } catch (\Throwable $e) {
            Log::warning('Shiprocket request failed', ['path' => $path, 'error' => $e->getMessage()]);
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Network error contacting courier.'];
        }

        if (in_array($resp->status(), [401, 403], true) && $retry) {
            Cache::forget(self::TOKEN_CACHE_KEY); // token revoked mid-life → refresh once
            return $this->request($method, $path, $params, false);
        }

        return [
            'ok'     => $resp->successful(),
            'status' => $resp->status(),
            'data'   => $resp->json(),
            'error'  => $resp->successful() ? null : ($resp->json('message') ?? 'Courier API error (' . $resp->status() . ').'),
        ];
    }

    /** Cached bearer token (forced refresh handled by the caller forgetting the key). */
    private function token(): ?string
    {
        // A permanent API token (if configured) is used directly — no login round-trip.
        if (!empty($this->apiToken)) {
            return $this->apiToken;
        }
        if (!$this->email || !$this->password) {
            return null;
        }
        return Cache::remember(self::TOKEN_CACHE_KEY, now()->addDays(9), function () {
            try {
                $resp = Http::timeout(20)->acceptJson()->post($this->baseUrl . '/v1/external/auth/login', [
                    'email'    => $this->email,
                    'password' => $this->password,
                ]);
                return $resp->successful() ? ($resp->json('token') ?: null) : null;
            } catch (\Throwable $e) {
                Log::warning('Shiprocket auth failed', ['error' => $e->getMessage()]);
                return null;
            }
        });
    }
}
