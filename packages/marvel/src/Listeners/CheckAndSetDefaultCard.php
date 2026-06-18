<?php

namespace Marvel\Listeners;

use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Marvel\Database\Models\PaymentMethod;
use Marvel\Events\PaymentMethods;

// Intentionally NOT ShouldQueue: clearing the previous default_card must happen in the same
// request that sets the new default, otherwise there is a window with two (or zero) default cards.
// It's a tiny state mutation on a low-frequency action — run it synchronously.
class CheckAndSetDefaultCard
{

    protected function fetchAllPaymentMethods()
    {
        return PaymentMethod::all();
    }

    /**
     * Handle the event.
     *
     * @param PaymentMethods $event
     * @return void
     */
    public function handle(PaymentMethods $event)
    {
        $currentPaymentMethods = $event->payment_methods;
        $allPaymentMethods = $this->fetchAllPaymentMethods();

        if ($currentPaymentMethods->default_card) {
            foreach ($allPaymentMethods as $key => $paymentMethod) {
                if ($paymentMethod->method_key !== $currentPaymentMethods->method_key) {
                    $paymentMethod->default_card = false;
                    $paymentMethod->save();
                }
            }
        }
    }
}
