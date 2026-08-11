<?php

namespace Marvel\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Marvel\Enums\EventType;
use Marvel\Events\OrderCreated;
use Marvel\Notifications\NewOrderReceived;
use Marvel\Notifications\OrderPlacedSuccessfully;
use Marvel\Traits\OrderSmsTrait;
use Marvel\Traits\SmsTrait;

class SendOrderCreationNotification implements ShouldQueue
{
    use SmsTrait, OrderSmsTrait;

    /**
     * Run only AFTER the order DB transaction commits, so a slow/failing mailer
     * can never add latency to, throw inside, or roll back order creation (the
     * OrderCreated event is fired inside the order transaction).
     */
    public $afterCommit = true;

    /**
     * Handle the event.
     *
     * @param OrderCreated $event
     * @return void
     */
    public function handle(OrderCreated $event)
    {
        $order = $event->order;
        $customer = $event->order->customer;
        $emailReceiver = $this->getWhichUserWillGetEmail(EventType::ORDER_CREATED, $order->language);
        // A mailer failure must never break the order — swallow + log.
        try {
            $engine = app(\Marvel\Services\EmailService::class);
            $vars = \Marvel\Services\OrderEmailVars::from($order);
            if ($customer && $emailReceiver['customer'] && $order->parent_id == null) {
                $engine->send('order.placed.customer', $customer->email, $vars, [
                    'fallback' => fn () => $customer->notify(new OrderPlacedSuccessfully($event->invoiceData)),
                ]);
            }
            // Guest orders: no account, but an optionally-provided email gets
            // the same confirmation — it carries the tokenized tracking link,
            // the guest's ONLY durable way back to their order (localStorage
            // was the sole copy before).
            $guestEmail = !$customer ? ($order->customer_email ?? null) : null;
            if ($guestEmail && $emailReceiver['customer'] && $order->parent_id == null) {
                $engine->send('order.placed.customer', $guestEmail, $vars, [
                    'fallback' => fn () => \Illuminate\Support\Facades\Notification::route('mail', $guestEmail)
                        ->notify(new OrderPlacedSuccessfully($event->invoiceData)),
                ]);
            }
            if ($emailReceiver['admin']) {
                $admins = $this->adminList();
                $engine->send('order.placed.admin', $admins->pluck('email')->all(), $vars, [
                    'fallback' => function () use ($admins, $order) {
                        foreach ($admins as $admin) {
                            $admin->notify(new NewOrderReceived($order, 'admin'));
                        }
                    },
                ]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Order-creation email failed', [
                'order_id' => $order->id ?? null,
                'error'    => $e->getMessage(),
            ]);
        }
        $this->sendOrderCreationSms($order);
    }
}
