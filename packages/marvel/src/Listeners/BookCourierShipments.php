<?php

namespace Marvel\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Shipment;
use Marvel\Enums\PaymentGatewayType;
use Marvel\Enums\PaymentStatus;
use Marvel\Services\Courier\CourierService;
use RuntimeException;
use Throwable;

/**
 * Auto-book courier shipments once an order is confirmed AND payable. Registered on BOTH
 * OrderProcessed (COD + full-wallet — confirmed at creation) and PaymentSuccess (prepaid —
 * confirmed only after the PSP settles). This split matters: a PREPAID order's OrderProcessed
 * fires while payment_status is still PENDING, so booking there would dispatch couriers for
 * orders that may never be paid (the stale-unpaid sweep would then cancel them). So we book
 * only when the order is COD or payment-success.
 *
 * ShouldQueue + the redis connection's after_commit ⇒ this runs OFF the checkout request,
 * AFTER the order transaction commits: a slow or failing shipping service can never block or
 * roll back a placed order. Idempotent — only legs with no provider booking yet
 * (provider_order_id / awb_number both null) are (re)booked, and the Go shipping-service is
 * itself idempotent on shipment_ref, so a duplicate event or a retry never double-books.
 * Transient failures throw ⇒ the queue retries with backoff ⇒ failed_jobs (DLQ);
 * courier:sweep-undispatched is the final alarm for anything left unbooked.
 *
 * A clean no-op while courier is disabled (the default) — zero behaviour change until an
 * operator turns the shipping service on.
 */
class BookCourierShipments implements ShouldQueue
{
    use InteractsWithQueue;

    /** Retry a struggling shipping service a handful of times, then land in failed_jobs. */
    public int $tries = 5;

    /** Backoff between retries, in seconds. */
    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }

    public function handle($event): void
    {
        $order = $event->order ?? null;
        if (!$order) {
            return;
        }

        // Resolve via the container (not `new`) so the courier layer can be faked in tests.
        $courier = app(CourierService::class);
        if (!$courier->enabled()) {
            return; // courier off / shipping service unconfigured — nothing to dispatch
        }
        // Auto-booking is opt-in (settings.options.courier.auto_book, default OFF). An order used
        // to be despatched to a partner the instant it became fulfillable, so an operator never
        // chose the vendor OR the courier. Manual booking is unaffected: it runs through
        // CourierShipmentController::dispatchShipment, which only needs enabled().
        if (!$courier->autoBookEnabled()) {
            return;
        }

        // Only dispatch for an order we can actually fulfil: COD (confirmed at placement) or a
        // prepaid order whose payment has succeeded. A prepaid-PENDING order waits for PaymentSuccess.
        $payable = PaymentGatewayType::isCashOnDelivery($order->payment_gateway)
            || $order->payment_status === PaymentStatus::SUCCESS;
        if (!$payable) {
            return;
        }

        // A terminal order (a late/replayed PaymentSuccess) must never book a courier.
        if (in_array($order->order_status, ['order-cancelled', 'order-refunded', 'order-failed'], true)) {
            return;
        }

        // Unbooked, non-terminal legs only. A leg that already carries a provider booking is
        // skipped — this is the idempotency guard for duplicate events and retries.
        $shipments = Shipment::with(['order', 'items'])
            ->where('order_id', $order->id)
            ->whereNull('provider_order_id')
            ->whereNull('awb_number')
            ->whereNotIn('status', ['cancelled', 'delivered', 'rto'])
            // Self-delivery legs are the vendor's to fulfil — booking one would
            // throw→retry→failed_jobs forever on a leg that must never dispatch.
            ->courierEligible()
            ->get();

        if ($shipments->isEmpty()) {
            return;
        }

        $failed = [];
        $seen = [];

        // Multi-PASS, because booking can now create work for itself: the pre-booking
        // revalidation may move a line to another vendor, which mints a NEW parcel and
        // reports it back as `replanned`. That is not a failure, so nothing throws, so the
        // retry below never fires — the new parcel would sit unbooked until the undispatched
        // sweep alarmed on it hours later. Bounded at 3: a re-plan of a re-plan is already
        // pathological, and anything still pending joins $failed and takes the normal retry.
        for ($pass = 0; $pass < 3 && $shipments->isNotEmpty(); $pass++) {
            $replanned = [];

            foreach ($shipments as $shipment) {
                $seen[] = (int) $shipment->id;
                try {
                    $res = $courier->book($shipment);
                } catch (Throwable $e) {
                    // Booking one leg must never abort the others.
                    $res = ['ok' => false, 'error' => $e->getMessage()];
                }

                foreach ((array) ($res['replanned'] ?? []) as $newId) {
                    $replanned[] = (int) $newId;
                }

                // REASSIGNED means every line left this parcel for a new one we are about to
                // book — the empty original is not a failure to retry, it no longer exists.
                $reassigned = ($res['code'] ?? null) === 'REASSIGNED' && !empty($res['replanned']);

                if (empty($res['ok']) && !$reassigned) {
                    $failed[] = $shipment->id;
                    Log::warning('courier.autobook.failed', [
                        'order_id'    => $order->id,
                        'shipment_id' => $shipment->id,
                        'error'       => $res['error'] ?? 'unknown',
                    ]);
                }
            }

            $pending = array_values(array_diff(array_unique($replanned), $seen));
            $shipments = $pending
                ? Shipment::with(['order', 'items'])
                    ->whereIn('id', $pending)
                    ->whereNull('provider_order_id')
                    ->whereNull('awb_number')
                    ->whereNotIn('status', ['cancelled', 'delivered', 'rto'])
                    ->courierEligible()
                    ->get()
                : collect();
        }

        // Anything the pass budget did not reach is treated as unbooked, so the retry below
        // picks it up rather than leaving it silently stranded.
        foreach ($shipments as $stranded) {
            $failed[] = $stranded->id;
        }

        // Any leg still unbooked ⇒ throw so the queue retries with backoff (already-booked legs
        // are filtered out above, so a retry only re-attempts the failures). After $tries the job
        // lands in failed_jobs; the sweep command is the final alarm.
        if (!empty($failed)) {
            throw new RuntimeException(
                'Courier booking failed for order ' . $order->id . ', shipments [' . implode(',', $failed) . ']'
            );
        }
    }

    public function failed($event, Throwable $e): void
    {
        Log::error('courier.autobook.exhausted', [
            'order_id' => $event->order->id ?? null,
            'error'    => $e->getMessage(),
        ]);
    }
}
