<?php

namespace App\Modules\Identity\Domain\Events;

use App\Shared\Domain\AbstractDomainEvent;

/**
 * Emitted (through the outbox) when a user authenticates. Other contexts react
 * to this without Identity knowing they exist — e.g. an audit log, a
 * last-seen tracker, or fraud/velocity checks. Carries no secrets.
 */
final class UserLoggedIn extends AbstractDomainEvent
{
    public function __construct(
        private readonly string $userUuid,
        private readonly string $role,
        private readonly ?string $nurseryId,
        private readonly ?string $ip = null,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'identity.user_logged_in';
    }

    public function payload(): array
    {
        return [
            'user_uuid'  => $this->userUuid,
            'role'       => $this->role,
            'nursery_id' => $this->nurseryId,
            'ip'         => $this->ip,
        ];
    }
}
