<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Shipment;
use Marvel\Services\Courier\CourierService;

/**
 * The "courier-reconcile command" that CourierService::applyStatus has documented since June and
 * which did not exist. Status normally arrives push-style (partner → Go /webhooks/{partner} → Go
 * outbox → POST /api/shipping/callback). Every hop in that chain can drop an event:
 *   - a partner that has never been given our webhook URL pushes nothing at all (Porter today),
 *   - a Laravel-side failure used to be acked 200 and the event was destroyed (fixed in
 *     WebHookController::shippingCallback — this command is the net for whatever it doesn't catch).
 * So: poll the shipments that are BOOKED but not finished, ask the service for live status, and
 * push it through the SAME applyNormalizedStatus seam the webhook uses. Nothing here is a second
 * status-transition implementation — monotonicity, terminal-stickiness, the order advance and the
 * activity log all stay in that one seam, which is also why re-running this is free.
 *
 * Bounded on purpose: each row costs one live partner Track call (Porter rate-limits to ~1/min per
 * order and swallows 429s), so it takes a --limit and runs on a slow cadence. Oldest-first by
 * last_status_at means never-tracked rows (NULL) go first, then the stalest.
 *
 * No-op when courier is off, when nothing is open, and — via the seam — when the partner reports a
 * status the shipment already has.
 */
class ReconcileShipmentsCommand extends Command
{
    protected $signature = 'courier:reconcile-shipments {--limit=100 : Max shipments to poll in one pass}';

    protected $description = 'Poll booked, non-terminal shipments and apply the partner status (safety net for lost callbacks).';

    public function handle(): int
    {
        $svc = app(CourierService::class);
        if (!$svc->enabled()) {
            return self::SUCCESS; // courier off / service not linked — nothing is booked through it
        }

        $limit = max(1, min(500, (int) $this->option('limit')));

        // NOT status='rto': applyNormalizedStatus persists EVERY return stage as status 'rto' and
        // keeps the stage in last_status, so excluding 'rto' here hid the entire return leg from
        // the poller and left rto_in_transit / rto_delivered reachable only by webhook.
        $open = Shipment::whereNotIn('status', ['delivered', 'cancelled'])
            ->where(function ($q) {
                // applyNormalizedStatus persists EVERY return stage as status 'rto' and keeps the
                // stage in last_status, so excluding 'rto' outright hid the whole return leg from
                // the poller and left rto_in_transit / rto_delivered reachable only by webhook.
                // A bare 'rto' with no stage is the legacy terminal value and stays skipped.
                $q->where('status', '!=', 'rto')
                  ->orWhereIn('last_status', ['rto_initiated', 'rto_in_transit']);
            })
            // BOOKED only: an unbooked leg has nothing to track (courier:sweep-undispatched alarms
            // on those). A cancelled booking keeps its provider ids, hence the status filter above.
            ->where(function ($q) {
                $q->whereNotNull('provider_order_id')->orWhereNotNull('awb_number');
            })
            // A self-delivery leg never had a courier — polling one is a guaranteed error per pass.
            ->courierEligible()
            ->orderBy('last_status_at') // NULL (never tracked) first on both MySQL and sqlite
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($open->isEmpty()) {
            return self::SUCCESS;
        }

        $changed = 0;
        $failed  = 0;

        foreach ($open as $shipment) {
            $before = (string) $shipment->status;
            try {
                $res = $svc->track($shipment);
            } catch (\Throwable $e) {
                // One unreachable partner must not abort the rest of the pass.
                $failed++;
                continue;
            }
            if (empty($res['ok'])) {
                $failed++;
                continue;
            }

            // The service answers with its booking row; `status` is already the normalized domain
            // status, `last_status` is the same value it last recorded.
            $data   = (array) ($res['data'] ?? []);
            $status = (string) ($data['status'] ?? $data['last_status'] ?? '');
            if ($status === '') {
                continue; // no usable info — not a failure, just nothing to say
            }

            $svc->applyNormalizedStatus($shipment, $svc->mapServiceStatus($status));
            if ((string) $shipment->status !== $before) {
                $changed++;
            }
        }

        Log::info('courier.reconcile', [
            'scanned' => $open->count(),
            'changed' => $changed,
            'failed'  => $failed,
        ]);
        $this->info("Reconciled {$open->count()} shipment(s): {$changed} advanced, {$failed} failed.");

        return self::SUCCESS;
    }
}
