<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Arr;
use Marvel\Database\Models\Shipment;
use Marvel\Services\Courier\CourierService;

/**
 * Re-track non-terminal courier shipments and re-apply their status through the SAME path as
 * the webhook (CourierService::applyStatus). This is the compensating sweep for any webhook
 * that was missed, spoof-rejected, or failed mid-processing (the webhook returns 200 on an
 * internal error rather than 5xx, to avoid Shiprocket retry storms). Idempotent + safe to
 * run often; a no-op when courier is disabled.
 */
class CourierReconcileCommand extends Command
{
    protected $signature = 'marvel:courier-reconcile {--limit=500 : Max shipments to re-track per run}';

    protected $description = 'Re-track open courier shipments and re-apply their status (recovers missed/failed webhooks)';

    public function handle(): int
    {
        $svc = new CourierService();
        if (!$svc->enabled()) {
            $this->info('Courier integration is not enabled; nothing to reconcile.');
            return self::SUCCESS;
        }

        $shipments = Shipment::where('fulfillment_mode', 'courier')
            ->whereNotNull('awb_number')
            ->whereNotIn('status', ['delivered', 'cancelled'])
            ->limit((int) $this->option('limit'))
            ->get();

        $applied = 0;
        foreach ($shipments as $shipment) {
            try {
                $res = $svc->track($shipment);
                if (empty($res['ok'])) {
                    continue;
                }
                // Shiprocket track shape: data.tracking_data.shipment_track[0].current_status
                $status = (string) (Arr::get($res, 'data.tracking_data.shipment_track.0.current_status')
                    ?? Arr::get($res, 'data.current_status') ?? '');
                if ($status !== '') {
                    $svc->applyStatus($shipment, $status);
                    $applied++;
                }
            } catch (\Throwable $e) {
                $this->warn("Shipment #{$shipment->id}: {$e->getMessage()}");
            }
        }

        $this->info("Courier reconcile: checked {$shipments->count()} shipment(s), applied {$applied} status update(s).");
        return self::SUCCESS;
    }
}
