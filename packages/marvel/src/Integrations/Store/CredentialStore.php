<?php

namespace Marvel\Integrations\Store;

use Marvel\Database\Models\IntegrationProvider;

/**
 * Where a provider's credential bag actually lives.
 *
 * The unit of work is the provider ROW, not a slug: the row is the registry of what the platform
 * manages, and the caller (IntegrationService) saves it exactly once after the store has staged
 * whatever it needs on it — the bag itself for the database driver, the secret name and version
 * for Secrets Manager. That single save is what keeps one audit row per change and one version
 * bump for the Go sync, whichever driver is bound.
 *
 * Reads never throw: a store outage degrades to "nothing stored here", and every caller already
 * falls through to the legacy/env source. Writes DO throw — an operator saving a key must see a
 * failed save, not a green toast over a secret that never landed.
 */
interface CredentialStore
{
    public const DRIVER_SECRETS_MANAGER = 'secrets_manager';
    public const DRIVER_DATABASE        = 'database';

    public function driver(): string;

    /** @return array<string,string>|null  null when nothing is stored for this row */
    public function get(IntegrationProvider $row): ?array;

    /**
     * Bags for many rows in as few round trips as the backend allows.
     *
     * @param  iterable<IntegrationProvider> $rows
     * @return array<string, array<string,string>>  provider_slug => bag; rows with nothing stored are omitted
     */
    public function getMany(iterable $rows): array;

    /**
     * Stage $bag for $row and return the new version id. Mutates the row (the caller saves).
     *
     * @param  array<string,string> $bag  the COMPLETE bag — merging is the caller's job
     */
    public function put(IntegrationProvider $row, array $bag, ?int $userId = null): string;

    public function delete(IntegrationProvider $row): void;

    /** The backend's name for this row's secret, or null when the backend has no such concept. */
    public function secretName(IntegrationProvider $row): ?string;
}
