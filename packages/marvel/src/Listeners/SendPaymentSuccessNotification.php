<?php

namespace Marvel\Listeners;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Marvel\Enums\EventType;
use Marvel\Events\PaymentSuccess;
use Marvel\Notifications\PaymentSuccessfulNotification;
use Marvel\Traits\OrderSmsTrait;
use Marvel\Traits\SmsTrait;

class SendPaymentSuccessNotification implements ShouldQueue
{
    use SmsTrait, OrderSmsTrait;

    /**
     * Handle the event.
     *
     * @param PaymentSuccess $event
     * @return void
     */
    public function handle(PaymentSuccess $event)
    {
        // Notifications must NEVER break payment confirmation. The queue runs
        // sync on some environments, so this listener executes INLINE inside the
        // POST /orders/payment request — a dead SendGrid (e.g. "Maximum credits
        // exceeded") or SMS gateway would otherwise bubble up and 500 the
        // confirmation AFTER the money was captured and the order already
        // marked paid. Each channel is isolated and swallowed (logged only).
        $order = $event->order;
        try {
            $emailReceiver = $this->getWhichUserWillGetEmail(EventType::ORDER_PAYMENT_SUCCESS, $order->language ?? DEFAULT_LANGUAGE);
            if ($emailReceiver['vendor']) {
                foreach ($order->children as $key => $child_order) {
                    $vendor_id = $child_order->shop->owner_id;
                    $vendor = User::findOrFail($vendor_id);
                    $vendor->notify(new PaymentSuccessfulNotification($order));
                }
            }

            $customer = $order->customer;
            if (isset($customer) && $emailReceiver['customer']) {
                app(\Marvel\Services\EmailService::class)->send(
                    'payment.success.customer',
                    $customer->email,
                    \Marvel\Services\OrderEmailVars::from($order),
                    ['fallback' => fn () => $customer->notify(new PaymentSuccessfulNotification($order))]
                );
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('payment-success email notification failed (non-fatal)', [
                'order_id' => $order->id ?? null, 'error' => $e->getMessage(),
            ]);
        }

        try {
            $this->sendPaymentDoneSuccessfullySms($order);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('payment-success SMS failed (non-fatal)', [
                'order_id' => $order->id ?? null, 'error' => $e->getMessage(),
            ]);
        }
    }
}
