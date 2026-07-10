<?php

namespace App\Modules\Analytics\Application\Subscribers;

use App\Modules\Analytics\Application\AnalyticsService;
use App\Shared\Events\IntegrationMessage;

/** Feeds order events into the analytics read model (off the request path). */
final class RecordAnalytics
{
    public function __construct(private readonly AnalyticsService $analytics)
    {
    }

    public function handle(IntegrationMessage $message): void
    {
        if ($message->eventName === 'sales.order_placed') {
            $this->analytics->recordOrderPlaced($message);
        }
    }
}
