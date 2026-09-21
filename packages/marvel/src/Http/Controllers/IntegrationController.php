<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\IntegrationProvider;
use Marvel\Integrations\Store\CredentialStoreUnavailable;
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

        $actors = $this->actorNames($rows->pluck('last_updated_by')->filter()->unique()->all());

        $items = [];
        foreach ($defs as $def) {
            $items[] = $this->card($def, $rows->get($def->slug), $actors);
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
                'environment'      => $this->integrations->environment(),
                'categories'       => ProviderRegistry::categories(),
                'sync_enabled'     => $this->sync->enabled(),
                // secrets_manager | database — so the page can say where a saved key goes.
                'credential_store' => $this->integrations->store()->driver(),
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
        $actors = $row?->last_updated_by ? $this->actorNames([$row->last_updated_by]) : [];

        return response()->json([
            'data' => array_merge($this->card($def, $row, $actors), [
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
            'environment'   => ['sometimes', 'string', 'in:sandbox,production,staging'],
            'configuration' => ['sometimes', 'array'],
            'credentials'   => ['sometimes', 'array'],
        ]);

        $attributes = array_intersect_key($validated, array_flip(['enabled', 'priority', 'environment']));

        try {
            $row = $this->integrations->put(
                $slug,
                $attributes,
                (array) ($validated['credentials'] ?? []),
                (array) ($validated['configuration'] ?? []),
                $request->user()?->id
            );
        } catch (CredentialStoreUnavailable $e) {
            // This environment must hold credentials in Secrets Manager and is not configured to.
            // Nothing was saved; say exactly which variable fixes it.
            return response()->json(['message' => $e->getMessage(), 'code' => 'CREDENTIAL_STORE_UNAVAILABLE'], 422);
        } catch (\Aws\Exception\AwsException $e) {
            // The store refused the write. The AWS error CODE is safe and actionable
            // (AccessDeniedException → an IAM policy); the message body is neither.
            return response()->json([
                'message' => 'Secrets Manager rejected the write (' . ($e->getAwsErrorCode() ?: 'unknown error') . '). Nothing was saved.',
                'code'    => 'CREDENTIAL_STORE_REJECTED',
            ], 502);
        }

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

    /**
     * The card payload — no secret values, ever.
     *
     * @param  array<int,string> $actors  user id => display name, looked up once by the caller
     */
    private function card(ProviderDefinition $def, ?IntegrationProvider $row, array $actors = []): array
    {
        $sources = $this->integrations->credentialSources($def->slug);

        return [
            'slug'              => $def->slug,
            'display_name'      => $row->display_name ?? $def->displayName,
            'category'          => $def->category,
            'blurb'             => $def->blurb,
            'priority'          => (int) ($row->priority ?? $def->priority),
            'enabled'           => (bool) ($row->enabled ?? false),
            'environment'       => $row->environment ?? $this->integrations->environment(),
            'configured'        => $this->isConfigured($def),
            'credentials_set'   => array_map(static fn (string $s) => $s !== 'none', $sources),
            // Per field: secrets_manager | database | env | none. "env" is a credential that
            // works but is not managed here — the admin renders it as "migrate".
            'credentials_source' => $sources,
            // The Secrets Manager reference, never its contents. Null until the first save
            // through the store (or on the database driver).
            'secret_name'       => $row->secret_name ?? null,
            'last_updated_by'   => $row->last_updated_by ?? null,
            'last_updated_by_name' => isset($row->last_updated_by) ? ($actors[(int) $row->last_updated_by] ?? null) : null,
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
     * Who changed what, for one provider — the audit rows the model has been writing since
     * 2026-07-29, which until now had no reader. Values were masked at write time; this only
     * adds the actor's name.
     */
    public function history(Request $request, string $slug): JsonResponse
    {
        if (!ProviderRegistry::has($slug)) {
            return response()->json(['message' => "Unknown integration provider: {$slug}"], 404);
        }
        if (!Schema::hasTable('integration_audits')) {
            return response()->json(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0]]);
        }

        $limit = min(max((int) $request->query('limit', 25), 1), 100);
        $page = DB::table('integration_audits')
            ->where('provider_slug', $slug)
            ->when($request->filled('environment'), fn ($q) => $q->where('environment', (string) $request->query('environment')))
            ->orderByDesc('id')
            ->paginate($limit);

        $actors = $this->actorNames(collect($page->items())->pluck('user_id')->filter()->unique()->all());

        $data = collect($page->items())->map(static function ($row) use ($actors) {
            return [
                'id'             => (int) $row->id,
                'environment'    => $row->environment,
                'action'         => $row->action,
                'changed_fields' => json_decode((string) $row->changed_fields, true) ?: [],
                'before'         => json_decode((string) $row->before, true) ?: null,
                'after'          => json_decode((string) $row->after, true) ?: null,
                'user_id'        => $row->user_id ? (int) $row->user_id : null,
                'user_name'      => $row->user_id ? ($actors[(int) $row->user_id] ?? null) : null,
                'ip'             => $row->ip,
                'created_at'     => $row->created_at,
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    /**
     * @param  int[] $userIds
     * @return array<int,string>
     */
    private function actorNames(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        try {
            return DB::table('users')->whereIn('id', $userIds)->pluck('name', 'id')
                ->map(static fn ($name) => (string) $name)
                ->all();
        } catch (\Throwable) {
            return [];
        }
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
        // WhatsApp posts to OUR api directly (not through the shipping service), and its panel
        // answers a different question — the callback URL + verify token to paste into Meta.
        if ($def->slug === 'whatsapp') {
            $base = rtrim((string) config('app.url'), '/');
            return [
                'url'         => $base === '' ? null : "{$base}/api/webhooks/whatsapp",
                'secret_set'  => $this->integrations->credentialsSet('whatsapp')['webhook_verify_token'] ?? false,
                'auth_header' => 'X-Hub-Signature-256',
                'token_field' => 'webhook_verify_token',
                'note' => 'Optional. Register in Meta → your app → WhatsApp → Configuration, subscribing to "messages". Without it OTP and order updates still send; you simply do not receive delivery receipts or customer replies.',
            ];
        }

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
