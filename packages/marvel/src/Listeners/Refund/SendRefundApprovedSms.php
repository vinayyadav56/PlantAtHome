<?php

namespace Marvel\Listeners\Refund;

use App\Events\RefundApproved;
use Illuminate\Contracts\Queue\ShouldQueue;
use Marvel\Traits\OrderSmsTrait;

/**
 * Refund approved → customer SMS (DLT PlantAtHome_Refund_Initiated when the
 * template is active; legacy refund-updated blob otherwise).
 *
 * Hooked on RefundApproved — fired exactly once at approval in
 * RefundController — and NOT on the Refund model's `updated` event, which
 * fires on every later edit and would re-send "refund initiated".
 */
class SendRefundApprovedSms implements ShouldQueue
{
    use OrderSmsTrait;

    public function handle(RefundApproved $event): void
    {
        $refund = $event->refund->fresh();
        if ($refund && $refund->order) {
            $this->sendRefundUpdateSms($refund);
        }
    }
}
