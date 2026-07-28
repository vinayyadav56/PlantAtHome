<?php

namespace Marvel\Integrations;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\IntegrationProvider;
use Throwable;

/**
 * Pushes delivery-partner credentials to the Go shipping-service.
 *
 * The monolith is canonical, but it is not where courier API calls happen — the Go service performs
 * every partner request, so it needs the keys. Rather than a second admin UI over there, credentials
 * are stored here and pushed on save, which is what makes "rotate a key without a redeploy"
 * actually true.
 *
 * The bag is SEALED with AES-256-GCM before it leaves this process. TLS already protects the hop,
 * but a plaintext secret in a JSON body still passes through request-logging middleware, APM traces
 * and proxy buffers on both sides; sealing means those only ever see ciphertext. The sealing key is
 * deliberately different from the X-Api-Key that authenticates the call, so holding one does not
 * grant the other.
 *
 * A failed push NEVER fails the save. This table is the source of truth; the row is marked out of
 * step and retried, because losing an admin's edit to a transient network error would be worse than
 * being briefly out of sync.
 */
class CredentialSync
{
    public function __construct(private ?IntegrationService $integrations = null)
    {
        $this->integrations = $integrations ?? new IntegrationService();
    }

    /** Is pushing configured and switched on? */
    public function enabled(): bool
    {
        return (bool) config('integrations.sync_to_shipping', false)
            && $this->baseUrl() !== ''
            && $this->apiKey() !== ''
            && $this->syncKey() !== '';
    }

    /**
     * Push one provider's credentials. Returns [ok, error].
     *
     * Only delivery providers that the shipping-service actually knows about are pushable; anything
     * else is a no-op rather than an error, so a caller can push blindly after any save.
     */
    public function push(string $slug): array
    {
        $def = ProviderRegistry::find($slug);
        if ($def === null || !$def->syncsToShipping) {
            return ['ok' => true, 'skipped' => true];
        }
        if (!$this->enabled()) {
            return ['ok' => false, 'error' => 'Credential sync is not configured (URL, API key and sync key are all required).'];
        }

        $row = $this->integrations->provider($slug);
        if ($row === null) {
            return ['ok' => false, 'error' => "No stored configuration for {$slug}."];
        }

        $bag = $this->integrations->credentialBag($slug);
        if ($bag === []) {
            return ['ok' => false, 'error' => "No credentials stored for {$slug} — nothing to push."];
        }

        // Non-secret config the service needs alongside the secrets. cod_supported rides here as a
        // string because the service reads its whole credential bag as string→string.
        foreach (['vehicle_type', 'cod_supported', 'matter', 'vehicle_type_id'] as $extra) {
            $value = $this->integrations->config($slug, $extra);
            if ($value !== null && $value !== '') {
                $bag[$extra] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        }

        try {
            $sealed = Sealer::seal($this->syncKey(), $bag);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Could not seal the credential payload: ' . $e->getMessage()];
        }

        $payload = [
            'environment' => $row->environment,
            'version'     => (int) $row->credentials_version,
            'base_url'    => (string) ($this->integrations->config($slug, 'base_url') ?? ''),
            'sealed'      => $sealed,
        ];

        try {
            $res = Http::withHeaders(['X-Api-Key' => $this->apiKey()])
                ->timeout(15)
                ->put($this->baseUrl() . '/v1/partners/' . urlencode($slug) . '/credentials', $payload);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Shipping service unreachable: ' . $e->getMessage()];
        }

        if (!$res->successful()) {
            $body = (string) $res->body();

            return ['ok' => false, 'error' => 'Shipping service returned ' . $res->status() . ': ' . mb_substr($body, 0, 300)];
        }

        $data = (array) $res->json();

        // The service answers 200 with applied=false when it ignored a stale version. Reporting
        // that as success would leave the row marked synced while the service kept the old key.
        if (array_key_exists('applied', $data) && $data['applied'] === false) {
            return ['ok' => false, 'error' => (string) ($data['message'] ?? 'Shipping service did not apply the credentials.')];
        }

        return ['ok' => true, 'fields' => (int) ($data['fields'] ?? count($bag))];
    }

    /**
     * Push and record the outcome on the row. Never throws — the caller has already saved.
     */
    public function pushAndRecord(string $slug): array
    {
        $def = ProviderRegistry::find($slug);
        if ($def === null || !$def->syncsToShipping) {
            return ['ok' => true, 'skipped' => true];
        }

        try {
            $res = $this->push($slug);
        } catch (Throwable $e) {
            $res = ['ok' => false, 'error' => $e->getMessage()];
            Log::warning("integration credential push threw for {$slug}: " . $e->getMessage());
        }

        $this->integrations->recordSync(
            $slug,
            !empty($res['ok']) ? IntegrationProvider::SYNC_SYNCED : IntegrationProvider::SYNC_FAILED,
            $res['error'] ?? null
        );

        return $res;
    }

    private function baseUrl(): string
    {
        return rtrim((string) ($this->integrations->config('shipping_service', 'url') ?? ''), '/');
    }

    private function apiKey(): string
    {
        return $this->integrations->secret('shipping_service', 'api_key');
    }

    private function syncKey(): string
    {
        $fromProvider = $this->integrations->secret('shipping_service', 'sync_key');

        return $fromProvider !== '' ? $fromProvider : (string) config('integrations.sync_key', '');
    }
}
