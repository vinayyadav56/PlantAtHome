<?php

namespace Marvel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Arr;
use Marvel\Database\Models\Shipment;
use Marvel\Services\Courier\BorzoAdapter;
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
        if (!$svc->enabled() && !$svc->borzoEnabled()) {
            $this->info('Courier integration is not enabled; nothing to reconcile.');
            return self::SUCCESS;
        }
        $limit = (int) $this->option('limit');
        $checked = 0;
        $applied = 0;

        // ── Shiprocket courier lane (track by AWB → mapStatus). ──
        // When the dedicated service owns shipping, IT reconciles + drives status via its callback;
        // the monolith must not re-track service-booked AWBs against its own Shiprocket account.
        if ($svc->enabled() && !$svc->shippingServiceEnabled()) {
            $shipments = Shipment::where('fulfillment_mode', 'courier')
                ->where(fn ($q) => $q->whereNull('provider')->orWhere('provider', '!=', 'borzo'))
                ->whereNotNull('awb_number')
                ->whereNotIn('status', ['delivered', 'cancelled'])
                ->limit($limit)
                ->get();
            foreach ($shipments as $shipment) {
                $checked++;
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
        }

        // ── Borzo instant/same-city lane (re-fetch order → mapBorzoStatus via the adapter). ──
        if ($svc->borzoEnabled() && !$svc->shippingServiceEnabled()) {
            $adapter = new BorzoAdapter();
            $borzoShipments = Shipment::where('provider', 'borzo')
                ->whereNotNull('provider_order_id')
                ->whereNotIn('status', ['delivered', 'cancelled'])
                ->limit($limit)
                ->get();
            foreach ($borzoShipments as $shipment) {
                $checked++;
                try {
                    $res = $adapter->track($shipment);
                    if (!empty($res['ok']) && !empty($res['normalized'])) {
                        $svc->applyNormalizedStatus($shipment, $res['normalized']);
                        $applied++;
                    }
                } catch (\Throwable $e) {
                    $this->warn("Shipment #{$shipment->id} (borzo): {$e->getMessage()}");
                }
            }
        }

        $this->info("Courier reconcile: checked {$checked} shipment(s), applied {$applied} status update(s).");
        return self::SUCCESS;
    }
}
