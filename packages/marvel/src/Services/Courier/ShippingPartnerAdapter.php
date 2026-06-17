<?php

namespace Marvel\Services\Courier;

use Illuminate\Http\Request;
use Marvel\Database\Models\Shipment;

/**
 * The partner-agnostic shipping seam (the generalization of the Shiprocket-shaped
 * CourierProviderInterface). Each delivery partner — Borzo (instant/same-city), Shiprocket
 * (intercity courier), Porter (next) — is ONE class implementing this interface; ALL of a
 * partner's request/response field names live inside its adapter and nowhere else. The domain
 * layer (CourierService) talks only to this contract, so adding a partner is: implement this +
 * register it in the AdapterRegistry — zero changes to callers.
 *
 * Like CourierProviderInterface, every method returns a structured array and NEVER throws for an
 * API-level failure, so the caller reasons about partial failure explicitly:
 *   ['ok' => bool, 'status' => int, 'data' => mixed, 'error' => ?string, ...]
 *
 * `book`, `track`, `cancel` take a Shipment because while we are modular-monolith-first the
 * adapter maps directly off our model; when the service is extracted (P5) these become DTOs.
 */
interface ShippingPartnerAdapter
{
    /** Stable partner code, e.g. "borzo" / "shiprocket". */
    public function code(): string;

    /**
     * What this partner can do — drives routing eligibility:
     *   ['modes' => ['instant','same_city','courier'], 'cod' => bool, 'multipoint' => bool, 'label' => bool]
     */
    public function capabilities(): array;

    /**
     * Price + ETA BEFORE booking (Borzo "calculate-order", Shiprocket "serviceability").
     * Returns ['ok','cost'=>float,'eta_days'=>?int,'cod_available'=>bool,'currency'=>'INR','raw'=>mixed,'error'].
     */
    public function quote(Shipment $shipment, bool $cod): array;

    /**
     * Create the partner order (+ AWB for courier partners) for this shipment and persist the
     * provider ids / tracking onto it. MUST be idempotent on the shipment's provider_order_id —
     * a retry after a partial failure never creates a duplicate order. Returns ['ok','shipment','error'].
     */
    public function book(Shipment $shipment): array;

    /** Cancel the partner order if its state still allows it. ['ok','error']. */
    public function cancel(Shipment $shipment, ?string $reason = null): array;

    /**
     * Live status for this shipment. Returns ['ok','normalized'=>?array{shipment_status,order_status},
     * 'raw'=>mixed,'error'] — `normalized` is what applyNormalizedStatus() consumes (already mapped
     * by the adapter, since each partner's status vocabulary differs).
     */
    public function track(Shipment $shipment): array;

    /**
     * Verify + translate an inbound webhook/callback into canonical status events:
     *   ['ok','signature_valid'=>bool,'events'=>[['shipment_ref'=>?string,'provider_order_id'=>?string,
     *     'normalized'=>?array]],'error'].
     * The handler may still re-fetch authoritative status via track() before applying (anti-spoof).
     */
    public function normalizeWebhook(Request $request): array;
}
