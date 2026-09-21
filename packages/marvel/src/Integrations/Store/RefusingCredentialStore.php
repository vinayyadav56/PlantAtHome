<?php

namespace Marvel\Integrations\Store;

use Marvel\Database\Models\IntegrationProvider;

/**
 * Wraps a store whose driver is NOT allowed in this environment: reads still work (the site
 * keeps running on whatever is already stored or in env), writes are refused with a message
 * that says exactly which variable to set.
 *
 * Bound in production and staging whenever the driver is anything but Secrets Manager. A save
 * that quietly landed in MySQL there would defeat the point of the module without anyone
 * noticing until an audit asked where the keys were.
 */
class RefusingCredentialStore implements CredentialStore
{
    public function __construct(private CredentialStore $inner, private string $reason)
    {
    }

    public function driver(): string
    {
        return $this->inner->driver();
    }

    public function get(IntegrationProvider $row): ?array
    {
        return $this->inner->get($row);
    }

    public function getMany(iterable $rows): array
    {
        return $this->inner->getMany($rows);
    }

    public function put(IntegrationProvider $row, array $bag, ?int $userId = null): string
    {
        throw new CredentialStoreUnavailable($this->reason);
    }

    public function delete(IntegrationProvider $row): void
    {
        throw new CredentialStoreUnavailable($this->reason);
    }

    public function secretName(IntegrationProvider $row): ?string
    {
        return $this->inner->secretName($row);
    }
}
