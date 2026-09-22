<?php

namespace Marvel\Integrations\Store;

use Aws\SecretsManager\Exception\SecretsManagerException;
use Aws\SecretsManager\SecretsManagerClient;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\IntegrationProvider;
use Throwable;

/**
 * Credentials live in AWS Secrets Manager, one secret per provider per environment:
 *
 *     plantathome/{environment}/{provider_slug}      e.g. plantathome/production/razorpay
 *
 * The secret value is the JSON bag keyed by the registry's credential field names. The database
 * keeps only the secret's name and the version id it last wrote — never a credential.
 *
 * Identity is the SDK's default chain on purpose (no `credentials` key, ever): the EC2 instance
 * profile in production, the environment on Railway staging, a developer's own profile locally.
 * No AWS key lives in this repository or in `.env` on an AWS box. Environment separation is IAM's
 * job — the production role may only touch `plantathome/production/*`, the staging user only
 * `plantathome/staging/*` — so a bug here cannot read across environments even if it tried.
 *
 * Secrets are created lazily on the first save. Secrets Manager bills per secret per month, so
 * pre-creating one for every registered provider would pay for ~50 empty secrets.
 */
class SecretsManagerCredentialStore implements CredentialStore
{
    private ?SecretsManagerClient $client;

    public function __construct(?SecretsManagerClient $client = null)
    {
        $this->client = $client;
    }

    public function driver(): string
    {
        return self::DRIVER_SECRETS_MANAGER;
    }

    public function secretName(IntegrationProvider $row): ?string
    {
        return self::nameFor((string) $row->provider_slug, (string) $row->environment);
    }

    public static function nameFor(string $slug, string $environment): string
    {
        $prefix = trim((string) config('integrations.secrets_manager.prefix', 'plantathome'), '/');

        return "{$prefix}/{$environment}/{$slug}";
    }

    public function get(IntegrationProvider $row): ?array
    {
        $name = $this->secretName($row);
        try {
            $res = $this->client()->getSecretValue(['SecretId' => $name]);

            return $this->decode($res['SecretString'] ?? null);
        } catch (SecretsManagerException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceNotFoundException') {
                Log::error('secrets manager read failed', ['secret' => $name, 'code' => $e->getAwsErrorCode()]);
            }

            return null;
        } catch (Throwable $e) {
            Log::error('secrets manager read failed', ['secret' => $name, 'error' => get_class($e)]);

            return null;
        }
    }

    public function getMany(iterable $rows): array
    {
        $bySecret = [];
        foreach ($rows as $row) {
            $bySecret[$this->secretName($row)] = (string) $row->provider_slug;
        }
        if ($bySecret === []) {
            return [];
        }

        $out = [];
        // BatchGetSecretValue takes up to 20 ids per call and reports a missing secret in
        // `Errors` rather than throwing, so one round trip covers a whole boot.
        foreach (array_chunk(array_keys($bySecret), 20) as $ids) {
            try {
                $res = $this->client()->batchGetSecretValue(['SecretIdList' => $ids]);
            } catch (Throwable $e) {
                Log::error('secrets manager batch read failed', ['count' => count($ids), 'error' => get_class($e)]);
                continue;
            }
            foreach ((array) ($res['SecretValues'] ?? []) as $value) {
                $slug = $bySecret[(string) ($value['Name'] ?? '')] ?? null;
                $bag = $this->decode($value['SecretString'] ?? null);
                if ($slug !== null && $bag !== null) {
                    $out[$slug] = $bag;
                }
            }
            foreach ((array) ($res['Errors'] ?? []) as $error) {
                if (($error['ErrorCode'] ?? '') !== 'ResourceNotFoundException') {
                    Log::error('secrets manager batch item failed', ['secret' => $error['SecretId'] ?? null, 'code' => $error['ErrorCode'] ?? null]);
                }
            }
        }

        return $out;
    }

    public function put(IntegrationProvider $row, array $bag, ?int $userId = null): string
    {
        $name = $this->secretName($row);
        $json = json_encode($this->strings($bag), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        try {
            $res = $this->client()->putSecretValue(['SecretId' => $name, 'SecretString' => $json]);
        } catch (SecretsManagerException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceNotFoundException') {
                throw $e;
            }
            $res = $this->client()->createSecret([
                'Name'         => $name,
                'Description'  => "PlantAtHome {$row->provider_slug} credentials ({$row->environment}). Managed from Admin → Settings → Integrations; never edit by hand.",
                'SecretString' => $json,
                'Tags'         => [
                    ['Key' => 'Environment', 'Value' => (string) $row->environment],
                    ['Key' => 'Service',     'Value' => (string) $row->provider_slug],
                    ['Key' => 'ManagedBy',   'Value' => 'plantathome-admin'],
                ],
            ]);
        }

        $version = (string) ($res['VersionId'] ?? '');

        // Staged on the row; the caller saves. secret_version_id is what the model's saving hook
        // watches to bump credentials_version on this path.
        $row->secret_name       = $name;
        $row->secret_version_id = $version !== '' ? $version : (string) now()->getTimestamp();
        $row->last_updated_by   = $userId;

        return $row->secret_version_id;
    }

    public function delete(IntegrationProvider $row): void
    {
        try {
            // A recovery window rather than ForceDeleteWithoutRecovery: a mis-click on the wrong
            // provider is recoverable for a week.
            $this->client()->deleteSecret(['SecretId' => $this->secretName($row), 'RecoveryWindowInDays' => 7]);
        } catch (SecretsManagerException $e) {
            if ($e->getAwsErrorCode() !== 'ResourceNotFoundException') {
                throw $e;
            }
        }
        $row->secret_name       = null;
        $row->secret_version_id = null;
    }

    private function client(): SecretsManagerClient
    {
        return $this->client ??= new SecretsManagerClient([
            'region'  => (string) config('integrations.secrets_manager.region', 'ap-south-1'),
            'version' => 'latest',
            // Deliberately no 'credentials' key — see the class docblock.
            //
            // Timeouts are NOT optional here. The SDK's default defaults_mode is 'legacy',
            // which leaves Guzzle's connect_timeout and timeout at 0 (unbounded), and this
            // client is reached from ConfigOverlay at BOOT — every request, every queue
            // worker, every minute's schedule:run. If the endpoint became unreachable at the
            // network level (a security-group change, a DNS failure) rather than answering,
            // each call would block on TCP SYN retries until the php-fpm pool was exhausted
            // and nginx returned 502 for the whole site. Bounded at roughly what the module's
            // own probes already use, so the worst case is "fall back to the env value".
            'http'    => ['connect_timeout' => 2, 'timeout' => 5],
            'retries' => 1,
        ]);
    }

    /** @return array<string,string>|null */
    private function decode(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $this->strings($decoded) : null;
    }

    /** @return array<string,string> */
    private function strings(array $bag): array
    {
        $out = [];
        foreach ($bag as $k => $v) {
            if (is_scalar($v)) {
                $out[(string) $k] = (string) $v;
            }
        }

        return $out;
    }
}
