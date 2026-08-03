<?php

namespace Marvel\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Marvel\Enums\EventType;
use Marvel\Events\OrderReceived;
use Marvel\Notifications\NewOrderReceived;
use Marvel\Traits\SmsTrait;

class SendOrderReceivedNotification implements ShouldQueue
{
    use SmsTrait;
    /**
     * Handle the event.
     *
     * @param OrderReceived $event
     * @return void
     */
    public function handle(OrderReceived $event)
    {
        $emailReceiver = $this->getWhichUserWillGetEmail(EventType::ORDER_CREATED, $event->order->language);
        if ($emailReceiver['vendor']) {
            $vendor = $event->order->shop->owner;
            if ($vendor) {
                app(\Marvel\Services\EmailService::class)->send(
                    'order.placed.vendor',
                    $vendor->email,
                    \Marvel\Services\OrderEmailVars::from($event->order),
                    ['fallback' => fn () => $vendor->notify(new NewOrderReceived($event->order))]
                );
            }
        }
    }
}
