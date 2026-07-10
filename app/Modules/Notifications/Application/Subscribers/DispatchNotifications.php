<?php

namespace App\Modules\Notifications\Application\Subscribers;

use App\Modules\Notifications\Application\NotificationService;
use App\Shared\Events\IntegrationMessage;

/**
 * The single outbox subscriber that turns domain events into notifications.
 * Registered for every event that has templates; idempotency lives in the
 * service (dedupe on event_id + channel).
 */
final class DispatchNotifications
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function handle(IntegrationMessage $message): void
    {
        $this->notifications->notifyFromEvent($message);
    }
}
