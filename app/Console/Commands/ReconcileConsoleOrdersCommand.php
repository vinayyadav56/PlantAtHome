<?php

namespace App\Console\Commands;

use App\Models\PartnerConsoleOrder;
use App\Models\PartnerWebhookEvent;
use App\Services\PartnerOrderLifecycle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Shipment;
use Marvel\Services\Courier\ShippingServiceClient;

/**
 * Keep the partner-order ledger alive: mirror partner webhooks, ingest shipment bookings, and
 * poll the active remainder — so /partner-orders shows what Porter is doing WHILE it happens
 * instead of a terminal state discovered on refresh.
 *
 * Three passes, every minute:
 *
 *  1. WEBHOOKS — pull new rows from the shipping service's webhook log (forward `after` cursor =
 *     max mirrored source id; one HTTP call per partner) and mirror them into
 *     partner_webhook_events (unique source id ⇒ idempotent). Each event is applied to its ledger
 *     row through PartnerOrderLifecycle — creating the row when NEITHER ledger knows the CRN
 *     (origin=webhook: the orders that used to vanish entirely).
 *
 *  2. SHIPMENTS — mirror shipment bookings into the ledger (origin=shipment). shipments
 *     OVERWRITES provider_order_id on rebook; the mirror row is what preserves the history. Their
 *     status comes from the shipments table — the Go service owns polling them, and tracking them
 *     here too would burn Porter's 1 Track/min/order twice for the same order.
 *
 *  3. TRACK — poll ACTIVE console/webhook rows the webhooks have gone quiet on. Bounded per pass;
 *     respects the 1/min/order limit via last_tracked_at; skips rows past the give-up rule (48h
 *     non-terminal, or 5 consecutive failed tracks) so a dead CRN is not polled forever.
 *
 * Ordering trusts arrival + the lifecycle rank guard, never Porter's event_ts (UAT stamps
 * order_start_trip with a bogus constant from 2022). Cancellation can only ever come from Porter
 * itself — nothing in these passes invents a status.
 */
class ReconcileConsoleOrdersCommand extends Command
{
    protected $signature = 'console-orders:reconcile
        {--track-limit=10 : Max partner Track calls in one pass}
        {--webhook-limit=200 : Max webhook rows to mirror per partner per pass}';

    protected $description = 'Mirror partner webhooks and keep partner-order lifecycle state current';

    private const PARTNERS = ['porter', 'borzo', 'shiprocket', 'shiprocket_quick'];
    private const GIVE_UP_HOURS = 48;
    private const GIVE_UP_FAILURES = 5;

    public function handle(): int
    {
        $client = new ShippingServiceClient();
        if (!$client->configured()) {
            return self::SUCCESS; // service not wired — off is success, not failure
        }

        $mirrored = $applied = $ingested = $tracked = $failed = 0;

        foreach (self::PARTNERS as $code) {
            [$m, $a] = $this->mirrorWebhooks($client, $code, (int) $this->option('webhook-limit'));
            $mirrored += $m;
            $applied += $a;
        }

        $ingested = $this->ingestShipments();
        [$tracked, $failed] = $this->trackActive($client, max(1, min(30, (int) $this->option('track-limit'))));

        Log::info('console-orders.reconcile', [
            'webhooks_mirrored' => $mirrored, 'events_applied' => $applied,
            'shipments_ingested' => $ingested, 'tracked' => $tracked, 'track_failed' => $failed,
        ]);
        $this->info("Mirrored {$mirrored} webhook(s) ({$applied} applied), ingested {$ingested} shipment(s), tracked {$tracked} ({$failed} failed).");

        return self::SUCCESS;
    }

    /**
     * The partner's order id, wherever that partner chose to put it.
     *
     * Porter sends it top-level (`order_id`); Borzo nests it under `order.order_id` in an
     * `order_changed` envelope. Reading only the top level meant every Borzo callback was filed
     * as `ignored / "no order_id in payload"` — the ledger could never learn anything from Borzo
     * even when a callback did arrive and verify.
     */
    private function webhookOrderId(array $body): string
    {
        foreach ([$body['order_id'] ?? null, $body['order']['order_id'] ?? null, $body['data']['order_id'] ?? null] as $candidate) {
            $id = trim((string) ($candidate ?? ''));
            if ($id !== '') {
                return $id;
            }
        }
        return '';
    }

    /** @return array{0:int,1:int} [mirrored, applied] */
    private function mirrorWebhooks(ShippingServiceClient $client, string $code, int $limit): array
    {
        $cursor = (int) PartnerWebhookEvent::where('partner_code', $code)->max('source_webhook_log_id');
        $res = $client->getPartnerWebhooks($code, ['after' => $cursor, 'limit' => max(1, min(200, $limit))]);
        if (empty($res['ok']) || !is_array($res['data'] ?? null)) {
            return [0, 0];
        }

        $mirrored = $applied = 0;
        foreach (($res['data']['items'] ?? []) as $item) {
            $body = json_decode((string) ($item['raw_body'] ?? ''), true);
            $orderId = $this->webhookOrderId(is_array($body) ? $body : []);

            $event = PartnerWebhookEvent::firstOrCreate(
                ['source_webhook_log_id' => (int) $item['id']],
                [
                    'partner_code'    => $code,
                    'porter_order_id' => $orderId !== '' ? $orderId : null,
                    'event_type'      => $item['event_type'] ?? null,
                    'partner_status'  => PartnerOrderLifecycle::fromEvent($item['event_type'] ?? ''),
                    'signature_valid' => (bool) ($item['signature_valid'] ?? false),
                    'payload'         => is_array($body) ? $body : null,
                    'event_ts'        => is_array($body) ? ($body['order_details']['event_ts'] ?? null) : null,
                    'received_at'     => $item['received_at'] ?? null,
                ],
            );
            if (!$event->wasRecentlyCreated) {
                continue; // already mirrored — idempotent by source id
            }
            $mirrored++;

            if ($orderId === '') {
                $event->update(['processed_at' => now(), 'processing_status' => 'ignored', 'processing_error' => 'no order_id in payload']);
                continue;
            }

            try {
                $row = $this->ledgerRowFor($code, $orderId);
                $event->partner_console_order_id = $row->id;
                $event->shipment_id = $row->shipment_id;

                $status = PartnerOrderLifecycle::fromEvent((string) $event->event_type);
                if ($status === null) {
                    // Carries no status of its own — Borzo's `order_changed` is a notification
                    // that something moved, not a claim about what. That is not nothing: the
                    // ledger row above is now created and linked, so pass 3 will track it and
                    // learn the real state from an authenticated fetch. Recorded as ignored
                    // because no status was APPLIED, with the reason spelled out.
                    $event->processing_status = 'ignored';
                    $event->processing_error = 'event carries no status — row linked, awaiting track';
                } elseif (PartnerOrderLifecycle::apply($row, $status, 'webhook')) {
                    $applied++;
                    $event->processing_status = 'applied';
                    $this->stampDriver($row, is_array($body) ? $body : []);
                } else {
                    // Rank guard refused it — an out-of-order or duplicate delivery. The event
                    // stays in the timeline; the state does not move. That is the idempotency
                    // rule working, not a failure.
                    $event->processing_status = 'stale';
                }
                $event->processed_at = now();
                $event->save();
            } catch (\Throwable $e) {
                $event->update(['processed_at' => now(), 'processing_status' => 'error', 'processing_error' => substr($e->getMessage(), 0, 500)]);
            }
        }
        return [$mirrored, $applied];
    }

    /** Find or create the ledger row a webhook belongs to, linking a real shipment when one matches. */
    private function ledgerRowFor(string $code, string $orderId): PartnerConsoleOrder
    {
        $row = PartnerConsoleOrder::where('partner_code', $code)->where('provider_order_id', $orderId)->first();
        if ($row) {
            return $row;
        }
        $shipment = Shipment::where('provider_order_id', $orderId)->first();
        return PartnerConsoleOrder::create([
            'partner_code'      => $code,
            'provider_order_id' => $orderId,
            'origin'            => $shipment ? 'shipment' : 'webhook',
            'shipment_id'       => $shipment?->id,
            'order_id'          => $shipment?->order_id,
        ]);
    }

    private function stampDriver(PartnerConsoleOrder $row, array $body): void
    {
        $row->syncDriverFrom($body);
    }

    /** Mirror shipment bookings into the ledger and keep their status in step — no partner calls. */
    private function ingestShipments(): int
    {
        $ingested = 0;
        $known = PartnerConsoleOrder::whereNotNull('provider_order_id')->pluck('provider_order_id')->all();
        $shipments = Shipment::whereNotNull('provider_order_id')
            ->whereNotNull('provider')
            ->whereNotIn('provider_order_id', $known ?: [''])
            ->orderByDesc('id')->limit(50)->get();
        foreach ($shipments as $sh) {
            PartnerConsoleOrder::firstOrCreate(
                ['partner_code' => (string) $sh->provider, 'provider_order_id' => (string) $sh->provider_order_id],
                ['origin' => 'shipment', 'shipment_id' => $sh->id, 'order_id' => $sh->order_id, 'mode' => $sh->mode],
            );
            $ingested++;
        }

        // Status for shipment-origin rows comes from the shipments table (the Go service polls
        // those); tracking them here too would double-bill Porter's 1/min/order.
        $rows = PartnerConsoleOrder::where('origin', 'shipment')
            ->whereNotNull('shipment_id')
            // A refused booking attempt is a row with NO CRN. It can never match a shipment's
            // provider_order_id, so the loop below skips it — but it stays non-terminal forever
            // and its updated_at never moves, so it sits at the head of this ordering and eats a
            // slot on every pass. 100 of them stall the mirror for every real booking.
            ->whereNotNull('provider_order_id')
            ->where(fn ($q) => $q->whereNull('partner_status')->orWhereNotIn('partner_status', PartnerOrderLifecycle::TERMINAL))
            ->orderBy('updated_at')->limit(100)->get();
        foreach ($rows as $row) {
            $sh = Shipment::find($row->shipment_id);
            if (!$sh || (string) $sh->provider_order_id !== (string) $row->provider_order_id) {
                continue; // the shipment was rebooked away from this CRN — this row is its history
            }
            $status = PartnerOrderLifecycle::fromNormalized((string) ($sh->last_status ?: $sh->status));
            if ($status !== null) {
                PartnerOrderLifecycle::apply($row, $status, 'shipment');
            }
        }
        return $ingested;
    }

    /** @return array{0:int,1:int} [tracked, failed] */
    private function trackActive(ShippingServiceClient $client, int $limit): array
    {
        $tracked = $failed = 0;
        $rows = PartnerConsoleOrder::whereIn('origin', ['console', 'webhook'])
            ->whereNotNull('provider_order_id')
            ->where(fn ($q) => $q->whereNull('partner_status')->orWhereNotIn('partner_status', PartnerOrderLifecycle::TERMINAL))
            ->where('created_at', '>', now()->subHours(self::GIVE_UP_HOURS))
            ->where('track_failures', '<', self::GIVE_UP_FAILURES)
            ->where(fn ($q) => $q->whereNull('last_tracked_at')->orWhere('last_tracked_at', '<', now()->subSeconds(60)))
            ->orderBy('last_tracked_at')
            ->limit($limit)->get();

        foreach ($rows as $row) {
            try {
                $res = $client->partnerTestTrack($row->partner_code, (string) $row->provider_order_id);
                $data = is_array($res['data'] ?? null) ? $res['data'] : [];
                $ok = !empty($res['ok']) && ($data['ok'] ?? null) !== false;
                $status = trim((string) ($data['status'] ?? ''));

                if (!$ok) {
                    // A refusal is information: record it, count it toward give-up, do NOT stamp
                    // last_tracked_at (the next pass retries until the failure cap).
                    $row->forceFill([
                        'track_failures'     => $row->track_failures + 1,
                        'last_error'         => (string) ($data['error'] ?? $res['error'] ?? 'track failed'),
                        'last_error_payload' => $data ?: null,
                        'last_error_at'      => now(),
                    ])->save();
                    $failed++;
                    continue;
                }
                if ($status === '') {
                    // ok:true with no status = Porter's swallowed 429 shape. Not stamping
                    // last_tracked_at means the next minute retries — stamping it would
                    // phase-lock the row stale forever.
                    $row->forceFill(['track_failures' => $row->track_failures + 1])->save();
                    $failed++;
                    continue;
                }

                $raw = $this->rawTrackBody($data);
                $mapped = PartnerOrderLifecycle::fromNormalized($raw['status'] ?? $status);
                if ($mapped !== null) {
                    PartnerOrderLifecycle::apply($row, $mapped, 'track');
                }
                $row->forceFill([
                    'latest_tracking_payload' => $raw ?: $data,
                    'last_tracked_at'         => now(),
                    'last_status'             => $status,
                    'track_failures'          => 0,
                ])->save();
                $row->syncDriverFrom($raw);
                $tracked++;
            } catch (\Throwable $e) {
                // One unreachable partner must not abort the rest of the pass.
                $row->forceFill(['track_failures' => $row->track_failures + 1, 'last_error' => substr($e->getMessage(), 0, 500), 'last_error_at' => now()])->save();
                $failed++;
            }
        }
        return [$tracked, $failed];
    }

    /** The partner's own track response body, out of the console exchange envelope. */
    private function rawTrackBody(array $data): array
    {
        $body = $data['exchange']['response']['body'] ?? null;
        if (is_string($body)) {
            $decoded = json_decode($body, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($body) ? $body : [];
    }
}
