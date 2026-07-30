<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\IntegrationProvider;
use Marvel\Integrations\ConnectionTester;
use Marvel\Integrations\CredentialSync;
use Marvel\Integrations\IntegrationService;
use Marvel\Integrations\ProviderDefinition;
use Marvel\Integrations\ProviderRegistry;

/**
 * Admin API for the Integration Management module.
 *
 * The read contract is the one already proven by CourierConfigController and the AI settings
 * controllers, and it is the whole security posture of this screen: a credential VALUE is never
 * returned. Reads answer `credentials_set: {field: bool}`, and writes only overwrite a field when a
 * non-empty value is supplied, so re-saving a form without retyping every secret cannot wipe the
 * ones it never displayed.
 *
 * There is deliberately no "reveal credential" endpoint. It would convert a write-only store into a
 * read-anywhere one, and the response shape is not covered by the request-side redaction in
 * LogRequests.
 */
class IntegrationController extends CoreController
{
    public function __construct(
        private IntegrationService $integrations = new IntegrationService(),
        private ConnectionTester $tester = new ConnectionTester(),
        private CredentialSync $sync = new CredentialSync(),
    ) {
    }

    /** Every provider as a card: status, environment, health, sync state. */
    public function index(Request $request): JsonResponse
    {
        $category = (string) $request->query('category', '');

        $defs = $category !== ''
            ? ProviderRegistry::byCategory($category)
            : ProviderRegistry::all();

        // Tolerate the table not existing yet (an environment where the migration has not run).
        // The whole module is designed to fall through to the legacy/env sources, so the listing
        // must still render — 500ing here would break the settings screen on a fresh deploy.
        try {
            $rows = IntegrationProvider::query()
                ->where('environment', $this->integrations->environment())
                ->get()
                ->keyBy('provider_slug');
        } catch (\Throwable) {
            $rows = collect();
        }

        $items = [];
        foreach ($defs as $def) {
            $items[] = $this->card($def, $rows->get($def->slug));
        }

        // Stable order: category first (display order), then priority, then name.
        $catOrder = array_flip(ProviderRegistry::categories());
        usort($items, static function ($a, $b) use ($catOrder) {
            return [$catOrder[$a['category']] ?? 99, $a['priority'], $a['display_name']]
                <=> [$catOrder[$b['category']] ?? 99, $b['priority'], $b['display_name']];
        });

        return response()->json([
            'data' => $items,
            'meta' => [
                'environment' => $this->integrations->environment(),
                'categories'  => ProviderRegistry::categories(),
                'sync_enabled' => $this->sync->enabled(),
            ],
        ]);
    }

    /** One provider with its full field schema, current non-secret config, and which secrets are set. */
    public function show(Request $request, string $slug): JsonResponse
    {
        $def = ProviderRegistry::find($slug);
        if ($def === null) {
            return response()->json(['message' => "Unknown integration provider: {$slug}"], 404);
        }

        $row = $this->integrations->provider($slug);

        return response()->json([
            'data' => array_merge($this->card($def, $row), [
                'fields'        => $def->fieldSchema(),
                'configuration' => $this->effectiveConfiguration($def),
                'docs_url'      => $def->docsUrl,
            ]),
        ]);
    }

    /**
     * Create or update a provider.
     *
     * Blank credential values are IGNORED (not stored as empty), which is what makes "leave blank to
     * keep" work. Saving a delivery partner also pushes credentials to the Go shipping-service — and
     * a failed push does NOT fail the save, because this table is canonical and losing an operator's
     * edit to a transient network error would be worse than being briefly out of step.
     */
    public function update(Request $request, string $slug): JsonResponse
    {
        $def = ProviderRegistry::find($slug);
        if ($def === null) {
            return response()->json(['message' => "Unknown integration provider: {$slug}"], 404);
        }

        $validated = $request->validate([
            'enabled'       => ['sometimes', 'boolean'],
            'priority'      => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'environment'   => ['sometimes', 'string', 'in:sandbox,production'],
            'configuration' => ['sometimes', 'array'],
            'credentials'   => ['sometimes', 'array'],
        ]);

        $attributes = array_intersect_key($validated, array_flip(['enabled', 'priority', 'environment']));

        $row = $this->integrations->put(
            $slug,
            $attributes,
            (array) ($validated['credentials'] ?? []),
            (array) ($validated['configuration'] ?? [])
        );

        $syncResult = null;
        if ($def->syncsToShipping && $this->sync->enabled()) {
            $syncResult = $this->sync->pushAndRecord($slug);
        } elseif ($def->syncsToShipping) {
            $this->integrations->recordSync($slug, IntegrationProvider::SYNC_PENDING, 'Credential sync is not configured.');
        }

        return response()->json([
            'data' => array_merge($this->card($def, $this->integrations->provider($slug)), [
                'configuration' => $this->effectiveConfiguration($def),
            ]),
            'sync' => $syncResult,
        ]);
    }

    /** Run the read-only connection probe and persist the resulting health state. */
    public function test(Request $request, string $slug): JsonResponse
    {
        if (!ProviderRegistry::has($slug)) {
            return response()->json(['message' => "Unknown integration provider: {$slug}"], 404);
        }

        $result = $this->tester->test($slug);

        // 200 with ok:false is the house contract for anything proxied to an external service: the
        // admin branches on the flag, and a transport-level error stays distinguishable from a
        // provider-level rejection.
        return response()->json([
            'ok'      => $result['ok'],
            'status'  => $result['status'],
            'message' => $result['message'],
            'detail'  => $result['detail'],
        ]);
    }

    /** Re-push credentials to the shipping service (the retry an operator can trigger by hand). */
    public function sync(Request $request, string $slug): JsonResponse
    {
        $def = ProviderRegistry::find($slug);
        if ($def === null) {
            return response()->json(['message' => "Unknown integration provider: {$slug}"], 404);
        }
        if (!$def->syncsToShipping) {
            return response()->json(['ok' => true, 'message' => 'This provider does not sync to the shipping service.']);
        }

        $result = $this->sync->pushAndRecord($slug);

        return response()->json([
            'ok'      => (bool) ($result['ok'] ?? false),
            'message' => $result['error'] ?? 'Credentials pushed to the shipping service.',
        ]);
    }

    /** The card payload — no secret values, ever. */
    private function card(ProviderDefinition $def, ?IntegrationProvider $row): array
    {
        return [
            'slug'              => $def->slug,
            'display_name'      => $row->display_name ?? $def->displayName,
            'category'          => $def->category,
            'blurb'             => $def->blurb,
            'priority'          => (int) ($row->priority ?? $def->priority),
            'enabled'           => (bool) ($row->enabled ?? false),
            'environment'       => $row->environment ?? $this->integrations->environment(),
            'configured'        => $this->isConfigured($def),
            'credentials_set'   => $this->integrations->credentialsSet($def->slug),
            'health_status'     => $row->health_status ?? IntegrationProvider::HEALTH_UNKNOWN,
            'health_checked_at' => optional($row?->health_checked_at)->toIso8601String(),
            'health_detail'     => $row->health_detail ?? null,
            'sync_status'       => $row->sync_status ?? IntegrationProvider::SYNC_NA,
            'sync_error'        => $row->sync_error ?? null,
            'synced_at'         => optional($row?->synced_at)->toIso8601String(),
            'syncs_to_shipping' => $def->syncsToShipping,
            'webhook'           => $this->webhook($def),
            'updated_at'        => optional($row?->updated_at)->toIso8601String(),
        ];
    }

    /**
     * What an operator has to paste into the partner's dashboard, and whether the matching secret
     * is set on our side.
     *
     * Computed, never stored. The URL is entirely derivable from the shipping-service base URL plus
     * the partner code, and a stored copy is just a second thing to keep in step — the failure mode
     * being a dashboard registered against a URL we no longer serve.
     *
     * Returns null for providers that do not call us back, so the admin renders nothing rather than
     * an empty webhook panel.
     */
    private function webhook(ProviderDefinition $def): ?array
    {
        // Only the delivery partners post status callbacks to us today.
        $tokenField = match ($def->slug) {
            'porter'     => 'webhook_token',
            'borzo'      => 'callback_token',
            'shiprocket' => 'webhook_token',
            default      => null,
        };
        if ($tokenField === null) {
            return null;
        }

        $base = rtrim((string) $this->integrations->config('shipping_service', 'url', ''), '/');

        return [
            'url'        => $base === '' ? null : "{$base}/webhooks/{$def->slug}",
            'secret_set' => $this->integrations->credentialsSet($def->slug)[$tokenField] ?? false,
            // The header name the partner must send the shared token under.
            'auth_header' => 'X-Api-Key',
            'token_field' => $tokenField,
            // Said plainly because it is the question this panel exists to pre-empt: a webhook that
            // never arrives is NOT lost. The service re-polls open bookings every 10 minutes, so
            // status still advances — a missing webhook costs latency, not correctness.
            'note' => 'If this is not registered, delivery status still updates via the 10-minute reconcile poll — slower, but nothing is lost.',
        ];
    }

    /** Every required credential present (from any source — stored, legacy table, or env). */
    private function isConfigured(ProviderDefinition $def): bool
    {
        $set = $this->integrations->credentialsSet($def->slug);
        foreach ($def->credentialFields as $field) {
            if (!empty($field['required']) && empty($set[$field['name']])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Non-secret configuration as it will actually be used, so the form shows the effective value
     * rather than an empty box next to a working env-configured provider.
     */
    private function effectiveConfiguration(ProviderDefinition $def): array
    {
        $out = [];
        foreach ($def->configNames() as $name) {
            $out[$name] = $this->integrations->config($def->slug, $name);
        }

        return $out;
    }
}
