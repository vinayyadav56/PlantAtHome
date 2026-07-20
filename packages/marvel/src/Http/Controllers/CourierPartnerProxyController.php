<?php

namespace Marvel\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Marvel\Enums\Permission;
use Marvel\Services\Courier\ShippingServiceClient;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Super-admin proxy to the Go shipping-service's per-partner RUNTIME config endpoints
 * (GET/PUT /v1/partners/{code}/config). Lets an operator flip a partner's master switch + per-API
 * COST toggles — e.g. Porter's paid get_quote / track — WITHOUT a redeploy.
 *
 * The toggle state lives in the shipping-service (it drives that service's autonomous reconcile /
 * track loops, which the monolith can't gate per-call), so this controller only FORWARDS. Partner
 * credentials are env-only and never handled here. It degrades gracefully: when the service is not
 * configured or unreachable it returns connected=false so the admin card shows a clear
 * "shipping service not connected" state instead of erroring.
 */
class CourierPartnerProxyController extends CoreController
{
    /** Partners whose runtime config may be proxied (defence-in-depth on the {code} path param). */
    private array $allowedPartners = ['porter', 'borzo', 'shiprocket'];

    public function show(Request $request, string $code)
    {
        $this->assertAdmin($request);
        $code = $this->assertPartner($code);

        $client = new ShippingServiceClient();
        if (!$client->configured()) {
            return $this->notConnected($code);
        }
        $res = $client->getPartnerConfig($code);
        if (empty($res['ok'])) {
            return $this->notConnected($code, $res['error'] ?? null);
        }
        return ['connected' => true, 'config' => $res['data']];
    }

    public function update(Request $request, string $code)
    {
        $this->assertAdmin($request);
        $code = $this->assertPartner($code);

        $validated = $request->validate([
            'enabled'        => 'sometimes|boolean',
            'quote_enabled'  => 'sometimes|boolean',
            'track_enabled'  => 'sometimes|boolean',
            'cancel_enabled' => 'sometimes|boolean',
        ]);
        // Forward only the toggles actually sent — the service does a partial merge over its current
        // effective state, so an untouched switch is never clobbered.
        $payload = [];
        foreach (['enabled', 'quote_enabled', 'track_enabled', 'cancel_enabled'] as $f) {
            if (array_key_exists($f, $validated)) {
                $payload[$f] = (bool) $validated[$f];
            }
        }
        // No toggles supplied → a no-op: return current state rather than forwarding an empty body
        // (Laravel serializes [] as JSON "[]", which the service's object decoder rejects with 400).
        if (empty($payload)) {
            return $this->show($request, $code);
        }

        $client = new ShippingServiceClient();
        if (!$client->configured()) {
            // Self-rendering HttpException so the status + reason survive (these routes are under
            // /api, where a plain MarvelException would surface as a bare 500).
            throw new HttpException(503, 'The shipping service is not configured; cannot save partner toggles.');
        }
        $res = $client->putPartnerConfig($code, $payload);
        if (empty($res['ok'])) {
            throw new HttpException(502, $res['error'] ?? 'Failed to update the partner configuration.');
        }
        return ['connected' => true, 'config' => $res['data']];
    }

    private function assertAdmin(Request $request): void
    {
        $user = $request->user();
        if (!$user || !$user->hasPermissionTo(Permission::SUPER_ADMIN)) {
            throw new AuthorizationException(NOT_AUTHORIZED);
        }
    }

    private function assertPartner(string $code): string
    {
        $code = strtolower(trim($code));
        if (!in_array($code, $this->allowedPartners, true)) {
            throw new HttpException(404, 'Unknown courier partner.');
        }
        return $code;
    }

    /** Graceful "service not reachable" shape so the admin card can render a disabled/offline state. */
    private function notConnected(string $code, ?string $error = null): array
    {
        return [
            'connected' => false,
            'error'     => $error,
            'config'    => [
                'code'       => $code,
                'configured' => false,
                'source'     => 'unavailable',
            ],
        ];
    }
}
