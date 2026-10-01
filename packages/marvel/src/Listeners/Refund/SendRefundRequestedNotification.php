<?php

namespace Marvel\Listeners;


use Illuminate\Contracts\Queue\ShouldQueue;
use Marvel\Enums\EventType;
use Marvel\Events\RefundRequested;
use Marvel\Traits\OrderSmsTrait;
use Marvel\Traits\SmsTrait;


class SendRefundRequestedNotification implements ShouldQueue
{
    use SmsTrait, OrderSmsTrait;

    /**
     * Handle the event.
     *
     * @param RefundRequested $event
     * @return void
     */
    public function handle(RefundRequested $event)
    {
        $refund = $event->refund;

        // Child (per-vendor) refund rows are an internal mirror of the parent — createChildOrderRefund
        // writes one per suborder, and the model fires `created` for each. Without this the customer
        // got one email AND one DLT SMS per vendor for a single refund request. The parent row
        // (shop_id === null) is the one the customer asked for.
        if ($refund->shop_id !== null) {
            return;
        }

        $customer = $refund->customer;
        $order = $refund->order;
        $emailReceiver = $this->getWhichUserWillGetEmail(EventType::ORDER_REFUND, $order->language);
        if ($emailReceiver['admin']) {
            $admins = $this->adminList();
            foreach ($admins as $admin) {
                $admin->notify(new RefundRequested($refund, 'admin'));
            }
        }
        if ($emailReceiver['customer']) {
            app(\Marvel\Services\EmailService::class)->send(
                'refund.requested.customer',
                $customer->email,
                \Marvel\Services\OrderEmailVars::from($refund->order),
                ['fallback' => fn () => $customer->notify(new RefundRequested($refund, 'customer'))]
            );
        }
        $this->sendRefundRequestedSms($refund);
    }
}
