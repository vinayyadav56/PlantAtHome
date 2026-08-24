<?php

namespace App\Services;

use App\Models\PartnerConsoleOrder;

/**
 * THE transition guard for the partner-order ledger.
 *
 * One partner order, one monotonic life: open → accepted → live → ended, with cancelled a
 * terminal interrupt at any point and reopened the one legal step backwards (Porter's driver
 * bailed; the order is searching again). Every writer — webhook events, track sweeps, manual
 * console tracks, cancels — routes through apply(), so no code path can push a ledger row
 * backwards or resurrect a terminal one. Before this, last_status was last-write-wins: a stale
 * "accepted" arriving after "live" simply won.
 *
 * Ordering deliberately trusts ARRIVAL order + rank, never the partner's event_ts: Porter UAT
 * stamps order_start_trip with a bogus constant (1651759452 — 2022, in seconds, while its
 * neighbours are ms). The rank guard makes out-of-order replays harmless anyway.
 *
 * partner_status carries the PARTNER's vocabulary; the internal normalized status stays in
 * last_status. The two are never mixed — that separation is the point.
 */
class PartnerOrderLifecycle
{
    /** Porter's own status words, ranked. ended/cancelled are terminal. */
    public const RANK = ['open' => 0, 'reopened' => 1, 'accepted' => 1, 'live' => 2, 'ended' => 3, 'cancelled' => 3];
    public const TERMINAL = ['ended', 'cancelled'];

    /** Go-normalized (test/track answers) → partner vocabulary. */
    private const FROM_NORMALIZED = [
        'pending'          => 'open',
        'assigned'         => 'accepted',
        'reopened'         => 'reopened',
        'out_for_delivery' => 'live',
        'delivered'        => 'ended',
        'cancelled'        => 'cancelled',
    ];

    /** Porter webhook event names → partner vocabulary. */
    private const FROM_EVENT = [
        'order_accepted'   => 'accepted',
        'order_start_trip' => 'live',
        'order_end_job'    => 'ended',
        'order_reopen'     => 'reopened',
        'order_cancel'     => 'cancelled',
    ];

    public static function fromNormalized(?string $status): ?string
    {
        $s = strtolower(trim((string) $status));
        // A raw partner word passing through (track exchange bodies carry Porter's own status)
        // is already the vocabulary we want.
        if (isset(self::RANK[$s])) {
            return $s;
        }
        return self::FROM_NORMALIZED[$s] ?? null;
    }

    public static function fromEvent(?string $eventType): ?string
    {
        return self::FROM_EVENT[strtolower(trim((string) $eventType))] ?? null;
    }

    /**
     * May $incoming replace $current? Terminal is sticky; rank must not regress; `reopened` is
     * the sole legal step back (live → reopened is real: the driver cancelled mid-search or even
     * mid-trip and Porter is reassigning). A repeat of the current status is a no-op, not an error.
     */
    public static function shouldApply(?string $current, string $incoming): bool
    {
        $current = strtolower(trim((string) $current)) ?: null;
        $incoming = strtolower(trim($incoming));
        if (!isset(self::RANK[$incoming]) || $current === $incoming) {
            return false;
        }
        if ($current !== null && in_array($current, self::TERMINAL, true)) {
            return false;
        }
        if ($current === null) {
            return true;
        }
        if ($incoming === 'reopened') {
            return true; // legal from any non-terminal state
        }
        if ($current === 'reopened') {
            return true; // any forward move out of a re-search
        }
        if (in_array($incoming, self::TERMINAL, true)) {
            return true; // cancel/end interrupt any non-terminal state
        }
        // $current comes off a DB column, not a constant. An unknown word — a foreign
        // partner_status, a hand-edited row, an older writer — must not fatal the reconcile
        // sweep for every row after it. Rank it below everything so a real partner status
        // can still replace it.
        return self::RANK[$incoming] > (self::RANK[$current] ?? -1);
    }

    /**
     * Apply a partner status to a ledger row: guard, stamp previous/changed-at/source and the
     * first-seen lifecycle timestamp, save. Returns whether anything changed.
     */
    public static function apply(PartnerConsoleOrder $row, string $partnerStatus, string $source): bool
    {
        $incoming = strtolower(trim($partnerStatus));
        if (!self::shouldApply($row->partner_status, $incoming)) {
            return false;
        }
        $now = now();
        $row->previous_partner_status = $row->partner_status;
        $row->partner_status = $incoming;
        $row->status_changed_at = $now;
        $row->status_source = $source;
        // First-seen only — a re-delivered event must not move history.
        $stampCol = ['accepted' => 'accepted_at', 'live' => 'live_at', 'ended' => 'ended_at', 'cancelled' => 'cancelled_at'][$incoming] ?? null;
        if ($stampCol !== null && $row->{$stampCol} === null) {
            $row->{$stampCol} = $now;
        }
        if ($incoming === 'reopened') {
            // Every reopen is a real occurrence — overwrite, the timeline keeps the history.
            $row->reopened_at = $now;
        }
        $row->save();
        if ($incoming === 'ended' && $source !== 'shipment' && $row->shipment_id) {
            // The ledger learned "delivered" first (webhook mirror) — push it through the one
            // shipments.status writer instead of waiting for the Go relay / 30-min reconcile.
            self::completeLinkedShipment($row);
        }
        return true;
    }

    /**
     * 'ended' is only reachable from delivered-ish inputs (delivered/order_end_job/raw ended;
     * cancels map to 'cancelled' and terminal-stickiness blocks ended-after-cancel), so routing
     * it as 'delivered' through CourierService::applyNormalizedStatus is safe. Idempotent on
     * both sides: shouldApply() fires this at most once per row, and applyNormalizedStatus
     * absorbs a duplicate from the relay/reconcile as a no-op.
     */
    private static function completeLinkedShipment(PartnerConsoleOrder $row): void
    {
        try {
            $shipment = \Marvel\Database\Models\Shipment::find($row->shipment_id);
            // A rebooked-away CRN is this shipment's history, not its live leg (same guard as
            // ReconcileConsoleOrdersCommand::ingestShipments).
            if (!$shipment || (string) $shipment->provider_order_id !== (string) $row->provider_order_id) {
                return;
            }
            $svc = app(\Marvel\Services\Courier\CourierService::class);
            $svc->applyNormalizedStatus($shipment, $svc->mapServiceStatus('delivered'));
        } catch (\Throwable $e) {
            // The ledger transition DID succeed; a cascade failure must not bubble into the
            // webhook mirror and mark the event 'error' (shouldApply refuses replays, so it
            // would never retry). The relay + courier:reconcile-shipments stay the backstop.
            \Illuminate\Support\Facades\Log::warning('ledger ended → shipment cascade failed', [
                'partner_console_order_id' => $row->id,
                'shipment_id' => $row->shipment_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
