<?php

namespace Marvel\Integrations\Store;

use Marvel\Database\Models\IntegrationProvider;
use Throwable;

/**
 * The bag lives in `integration_providers.credentials` (cast `encrypted:array`, APP_KEY).
 *
 * This is where every credential lived before Secrets Manager, and it stays the driver for local
 * development — a laptop should not need an AWS identity to run the app. Production and staging
 * are bound to Secrets Manager and refuse this driver (see the container binding).
 */
class DatabaseCredentialStore implements CredentialStore
{
    public function driver(): string
    {
        return self::DRIVER_DATABASE;
    }

    public function get(IntegrationProvider $row): ?array
    {
        try {
            $bag = $row->credentials;
        } catch (Throwable) {
            // A bag encrypted under a rotated APP_KEY: report "nothing stored" so the caller falls
            // through to env, rather than throwing on every read until someone re-enters it.
            return null;
        }

        return is_array($bag) && $bag !== [] ? $this->strings($bag) : null;
    }

    public function getMany(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $bag = $this->get($row);
            if ($bag !== null) {
                $out[(string) $row->provider_slug] = $bag;
            }
        }

        return $out;
    }

    public function put(IntegrationProvider $row, array $bag, ?int $userId = null): string
    {
        $row->credentials = $this->strings($bag);
        $row->last_updated_by = $userId;

        // The model's saving hook bumps credentials_version when the bag is dirty; that number is
        // the version the Go sync carries, so it is the honest "version id" here.
        return 'db:' . ((int) $row->getOriginal('credentials_version', 0) + 1);
    }

    public function delete(IntegrationProvider $row): void
    {
        $row->credentials = null;
    }

    public function secretName(IntegrationProvider $row): ?string
    {
        return null;
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
