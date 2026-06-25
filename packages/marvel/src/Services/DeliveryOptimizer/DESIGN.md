# Delivery Optimizer — DESIGN

> **Status:** proposal for review. No optimizer code is written until this is approved.
> **Scope of this round:** the optimization *brain* + caching + estimated-rate fallback + the
> bulk-quote contract + tests + a load harness. The heavy real-time infra (Kafka, worker
> fleet, WebSocket push) is **deliberately deferred** — see §12. The shipping integrations
> (Porter/Borzo, Shiprocket) are **not** reimplemented; we call the existing `shipping-service`.

---

## 1. Overview & goals

Given a cart (`items[]`) and the customer's `city`/`pincode`, choose the cheapest **valid**
fulfillment plan `{item → vendor → rail → shipment}` and return:

- a **consolidated set of shipments** — items from the same `(vendor, rail)` packed into **one
  delivery leg**, so we pay **one delivery fee per leg, not one per item** (the core cost lever);
- **one flat delivery fee** shown to the customer (owner absorbs the difference);
- **per-shipment delivery date ranges**;
- an **upsell hint** ("Add ₹X for free delivery") to maximise cart value;
- internally, the **true cost** of the plan for accounting/reconciliation.

Hard constraints honoured: plants are city-restricted (city-local vendors, Porter rail only);
tools/seeds/fertilizers may ship locally (Porter) or from anywhere (Courier); stock; vendor
serviceability; delivery SLA. Target: **p99 < 150 ms** on the hot path.

### Honest framing on scale
The prompt targets 100k orders/min (≈50–80k cart events/sec). Today the cart is **client-side
localStorage**, the server only sees it at `/checkout/estimate` (throttled 60/min), and vendor
inventory is largely dormant. So that number is **aspirational**. We build the algorithm and the
caching/estimation layer correctly now (they work at any scale) and **defer** the event-bus +
worker-fleet + live-push infra, documenting the math and the extraction path. The genuinely hard
part is *not* the scale — it is that calling Porter/courier APIs live on every cart-add is slow
and rate-limited; real marketplaces browse on **cached/estimated** rates and get **firm** quotes
only at checkout. That is exactly what we do.

---

## 2. Architecture

A clean, dependency-injected PHP module inside the `marvel` package, wired **additively** into the
existing checkout endpoints. Nothing in the current flow changes unless a feature flag is on.

```
DeliveryOptimizerService
        │  (orchestrator: optimizeCart / incrementalAdd / incrementalRemove)
        ├── CandidateProviderInterface   ─→ wraps ItemAssignmentService (Phase A, EXISTS)
        ├── ShippingQuoteClientInterface ─→ FirmQuoteClient → shipping-service /v1/quotes
        │                                   EstimatedRateQuoter → vendor_shipping_rates
        ├── QuoteCacheInterface          ─→ RedisQuoteCache (bucketed) + ServiceabilityCache
        └── OptimizerConfigInterface     ─→ settings.options + config/deliveryoptimizer.php

PhaseB: ShipmentGrouper → GreedyAssigner → TwoOptRefiner  (objective in CostModel)
State : CartMemoStore (memoised plan + incremental assignment state)
```

**The four interfaces are the seam.** Today they are in-process PHP. When scale justifies it, the
optimizer core (`CostModel`, `GreedyAssigner`, `TwoOptRefiner`) lifts wholesale into a Go/Python
worker and these interfaces become RPC clients — callers don't change.

**Integration points (additive, non-breaking):**
- `POST /checkout/estimate` (`LocationPriceController::checkoutEstimate`) — the browse/preview seam.
- `CheckoutRepository::verify` — the firm checkout seam (ships after the estimate seam is proven).

---

## 3. Phase A — candidate generation (reuse, don't duplicate)

`Marvel\Services\ItemAssignmentService::candidatesFor(productId, variationOptionId, qty, city, pincode)`
**already** returns ranked vendor candidates for one line, each carrying
`{shop_id, fulfillment_mode (local|courier), eta_days, sla_days, shipping_cost, selling_price,
score, recommended}`, after hard filters (in-stock for qty; serves the city locally/by courier;
plant/vertical gate via `ServiceAvailabilityService`; Operations "stop deliveries" kill-switch).
`local` is always ranked above `courier`.

We **wrap** it via `CandidateProviderInterface`:
- `candidates(CartItem, UserLocation, k=5)` → top-K candidates (the K cap bounds Phase B).
- `warm(cartItems, UserLocation)` → **one** batched pre-load of every vendor's shop/area/rate rows
  and a per-request memo of the city/vertical serviceability gate.

**Why `warm()` matters (N+1 fix):** `candidatesFor` memoises shop/area/rate *within* a call but
re-runs the `ServiceAvailabilityService` gate and re-derives vendor sets **per line**. A 50-line
cart would do 50× the gate work and repeat `whereIn` loads. `warm()` collapses these into ~3
queries (`whereIn` over the union of candidate shop_ids) + a single serviceability map, so Phase A
is `O(N · (Vp + K log K))` with a tiny constant.

Rail mapping: `local → INSTANT` (Porter/Borzo, `same_city`/`instant`), `courier → COURIER`
(Shiprocket). A line with zero candidates is an **unfulfillable** item (excluded from totals,
mirroring today's `checkoutEstimate`).

---

## 4. Phase B — assignment & consolidation

This is the new work. `N` = cart lines, `K` = top-K cap (5), `V` = distinct `(vendor, rail)` groups.

```
# 1. Candidate shipments: group candidates by (vendor, rail). An item can appear in
#    several candidate shipments (one per vendor that can fulfil it on a rail).
for item, cands in candidates:
    for c in cands:
        groups[(c.shop_id, railOf(c))].addOption(item, c)          # O(N·K)

# 2. Greedy: assign each item to the shipment of least MARGINAL cost. Process items in
#    ascending |viable shipments| (most-constrained first → fewer regrets).
for item in sortByFewestViable(items):
    best = argmin over item's viable shipments S of marginalCost(S, item)
    assign(item → best); recompute S.trueCost (delivery fee counted ONCE)   # O(N·deg)

# 3. Bounded 2-opt under a wall-clock budget (~30ms): swap/move items between shipments,
#    and "evacuate" a 1-item shipment into another viable leg to delete its whole fee.
while now < deadline and improvedLastPass:
    try every movable (item_i, item_j) cross-shipment swap/move; apply if it lowers `total`
```

**Objective** (in `CostModel`, isolated so a solver can replace it later):
```
total = Σ_legs deliveryFee(leg) + Σ_items productCost(item, vendor) + Σ_items slaPenalty(item)
slaPenalty(item)        = max(0, eta_days − target_sla) × slaPenaltyPerDay
marginalCost(S, item)   = [deliveryFee(S + item) − deliveryFee(S)] + productCost(item,S.vendor) + slaPenalty(item)
```
The first bracket is **≈ 0 when the item fits S's existing `(vendor, rail)`** (only a weight-slab
bump can add anything). That zero is the entire consolidation win: a second item from Vendor A on
the same rail rides for free vs. opening a new leg (a full fee). `deliveryFee` comes from the quote
layer (§5): an **estimate** during browse, a **firm** quote for the chosen legs at checkout.

---

## 5. Complexity

- Phase A: `O(N · (Vp + K log K))`, `Vp` = vendors per product; `warm()` makes the DB cost ~3 queries.
- Greedy: `O(N · K)`.
- 2-opt: `O(P²)` per pass over `P` movable pairs, but **hard-bounded by wall-clock** (`timeBudgetMs`,
  ~30ms) → worst case `O(budget)`, not `O(P²)`.
- The endpoint caps the cart at 50 lines; realistic `V ≤ ~12`. Pure-CPU search is **microseconds**.
  The latency floor is the *quote fan-out*, not the search — which is why caching (§7) and
  estimate-first browse (§8) are the real levers.

---

## 6. Heuristic vs exact ILP/MILP

Phase B is a **capacitated set-partitioning / facility-location** problem — NP-hard. An exact
ILP/MILP (open-leg binaries + assignment binaries + SLA constraints) is rejected on the hot path:

1. **No solver in a PHP-FPM image** (CBC/GLPK/Gurobi) — a new, heavyweight runtime dependency.
2. **Long-tail solve time** — even small MILPs have unpredictable tails that blow a 150ms p99.
3. **Unnecessary** — instances are tiny and *near-decomposable*: each item has ≤ K=5 viable
   shipments, and vendors rarely overlap across verticals, so greedy + bounded 2-opt reaches the
   optimum (or within one delivery fee) on essentially every real cart, in **bounded constant
   time, with zero new deps and a deterministic latency profile**.

The objective and cost model live in `CostModel`, so a future Go/Python worker can swap in an exact
solver behind the same interface without touching callers. We will run an **offline ILP** over
sampled carts to measure the heuristic's optimality gap as a regression guard.

---

## 7. Caching (bucketed, multi-layer)

| Layer | Key | TTL | Invalidation |
|---|---|---|---|
| Quote | `do:q:{rail}:{origin_pin}:{dest_pin}:{wbucket}:{dbucket}:{cod}` | INSTANT 60s / COURIER 300s | TTL |
| Serviceability | `do:svc:{origin_pin}:{dest_pin}:{rail}` → bool/partners | 6–24 h | TTL / courier-config change |
| Catalog/stock | reuse `products:ver` + `do:cat:{shop}:{product}` | until stock event | bust in `AvailabilityService::recomputeForProduct` (existing seam in `VendorInventoryWriter`) |
| Cart memo | `do:cart:{user|session}:{cartHash}` → plan + assignment state | ~15 m | replaced on each add/remove |

**Bucketing is the hit-rate lever.** A live carrier quote is a function of
`(origin_pin, dest_pin, weight, dims, rail)`. We quantise: **weight** → round up to the next 250 g
step (snap to carrier slabs 500/1000/2000 g); **dims** → volumetric-weight class → same bucketing.
Pure functions in `Support/Buckets.php` (unit-tested) so keys are stable and collisions are
*intentional* (approximate rate, vastly higher hit rate). Reads use Redis **MGET** for the whole
cart at once.

---

## 8. Estimated-rate fallback (browse vs checkout)

- **Browse / cart preview (`/checkout/estimate`):** `EstimatedRateQuoter` computes the leg fee from
  `vendor_shipping_rates` (`base_cost + per_kg × ceil(weight_kg)`, same row-selection
  `ItemAssignmentService::shippingCost` already uses), preferring a cached firm quote when present.
  It **never** calls the shipping-service synchronously unless `firmQuotesAtBrowse=true` (default
  **false**).
- **Checkout / committed plan:** `FirmQuoteClient` calls `shipping-service /v1/quotes` (or the batch
  endpoint, §11) **only for the chosen legs** (post-consolidation), or on a cache miss; results are
  written back into the quote cache so the next browse is firm-cached.
- **Backpressure / timeouts:** every firm call has a **tight ~120ms timeout** (the existing client
  default is 25s — fine for booking, fatal here). On timeout/error/over-budget → fall back to the
  estimate and flag `quote_source: estimate`. The optimizer **never blocks** on the shipping service.
- **Idempotency:** each firm request carries an `event_id` (UUID from `cartHash` + leg signature) so
  retried batch calls de-dupe server-side.

---

## 9. Incremental recompute & staleness

We model the cart plan as a **mutable assignment state** in `CartMemoStore`. We do **not** re-solve
the whole cart on every event:

- **Add item:** run Phase A for the new item only, then greedy-place it into the **best existing
  shipment** (one step, `O(K)`). Full Phase B re-opt only if the item's marginal cost crosses
  `marginalReoptThreshold` (it would open an expensive new leg, or make a prior consolidation
  suboptimal) **or** the event counter hits `fullReoptEveryNEvents`.
- **Remove item:** drop it; if that empties a leg, the fee disappears for free. Same threshold/
  counter gate (removal can make a *merge* newly profitable).

**Trade-off:** incremental placement is greedy and can drift **≤ one delivery fee** from the global
optimum across a long edit sequence; the threshold + N-event gates bound that drift. Bucketing
trades a few rupees of rate precision for an order-of-magnitude hit-rate gain. **Both are corrected
at checkout:** firm quotes replace estimates, and `CheckoutRepository::verify` re-runs assignment
server-authoritatively before the customer commits — that is the backstop against any stale memo
(e.g., a stock event mid-session).

---

## 10. Output contract

```
optimizeCart(CartItem[] cartItems, UserLocation loc) → OptimizationResult
  // toArray()  (CUSTOMER-SAFE — public /checkout/estimate + verify):
  {
    shipments: [ { shipment_id, items:[{product_id, variation_option_id, qty}],
                   eta_min_date, eta_max_date } ],
    customer_flat_fee,        // config-driven; 0 when free-delivery threshold met
    subtotal,
    delivery_estimates: [ { shipment_id, eta_min_date, eta_max_date } ],
    upsell_hint: { gap_to_free_delivery, threshold } | null,
    quote_source: "firm" | "estimate" | "mixed",
    unfulfillable_items: [ ... ]
  }
  // toInternalArray()  (ADMIN / LOGGING ONLY):
  //   adds internal_true_cost and per-shipment { vendor_id, rail, true_cost, quote_source }.
```
**Seller anonymity / cost privacy:** `internal_true_cost` and per-shipment `vendor_id`/`true_cost`/
`rail` reveal the platform's economics and which vendor fulfils what — so `toArray()` (the only thing
returned on customer-facing responses) omits them; they live solely in `toInternalArray()`.

`customer_flat_fee` and the free-delivery threshold read the **same** `settings.options.freeShipping`
/ `freeShippingAmount` that `CheckoutRepository::verify` uses, mirroring its logic exactly — including
the `freeShippingAmount`-unset ⇒ free-at-any-amount edge — and on the checkout path the gate uses the
server-authoritative order `amount`, so preview and charged totals agree. `upsell_hint.gap = max(0,
threshold − subtotal)`. ETAs come from `eta_days` via Carbon (a single `now()` per plan; the firm
carrier ETA widens the upper bound at checkout so dates never under-promise).

> **Firm-quote timeout caveat (impl note):** this repo pins Laravel 10, whose `Http::timeout(int)`
> would truncate a sub-second float (0.12 → 0 = curl "no timeout"). `ShippingServiceClient` therefore
> sets the raw Guzzle `timeout`/`connect_timeout` via `withOptions()` for the optimizer's tight
> firm-quote path; the booking path keeps the original `timeout(int)` call unchanged.

### Worked example (the locked acceptance test)
Cart: 5 plants (P1–P4 @ Vendor A local, P5 @ Vendor B local), 1 fertilizer @ C (courier),
1 seed @ D (courier), 1 tool @ D (courier). Greedy (most-constrained first): each plant has only its
one local vendor → **A-leg** gets P1–P4 (one Porter fee, not four), **B-leg** gets P5; **C-leg** gets
F; **D-leg** gets S; **T**'s marginal cost into D's existing courier leg ≈ 0 vs a full new fee →
**T joins D**. 2-opt finds no improving swap. Result = **exactly 4 shipments**
`{A:[P1-4]}, {B:[P5]}, {C:[F]}, {D:[S,T]}`, `internal_true_cost` = **4 fees (not 8)**, **one**
`customer_flat_fee`, distinct per-leg dates. This is asserted verbatim in `ConsolidationScenarioTest`.

---

## 11. Shipping-service quote contract

**Single (exists)** — `POST /v1/quotes`, `X-Api-Key`:
```
{ shipment_ref, order_ref, shop_ref, mode: same_city|instant|courier, cod, cod_amount,
  pickup:{pincode,lat,lng,...}, drop:{...}, items:[{name,sku,qty,unit_price,weight_g}], weight_g }
→ { mode, quotes:[{partner, cost, eta_days, cod_available, currency}], cheapest }
```
We add thin `quoteRaw(array $body, ?int $timeoutMs)` to `ShippingServiceClient` (reusing its private
`request()`, but with the tight optimizer timeout); the optimizer builds a *prospective-shipment*
payload from cart primitives (vendor `Shop` pickup → `pickup`, `UserLocation` → `drop`, the items
assigned to that `(vendor,rail)` → `items`, Σ `product.weight×qty` (500 g default) → `weight_g`,
rail → `mode`) — no persisted `Shipment` needed.

**Batch (to be PROVIDED BY shipping-service)** — `POST /v1/quotes/batch`:
```
{ quotes:[ { ref:"<event_id>", mode, cod, cod_amount, pickup, drop, weight_g } ] }
→ { results:[ { ref, ok, quotes:[...], cheapest } ] }
```
Until it ships, `quoteBatch` falls back to `Http::pool` of N parallel `quoteRaw` calls. The optimizer
only ever quotes the **chosen, post-consolidation legs** — never every candidate.

---

## 12. Scaling math & failure modes

**Scaling (documented; the heavy infra is NOT built this round).** Quotes key on
`(origin_pin, dest_pin, wbucket, dbucket, rail)`; per city the distinct-key space is
~`Vorigins × Ddest-buckets × 3 × 2` ≈ low-thousands → steady-state **cache hit ~95–99%**. With
estimate-first browse, browse events make **zero** shipping calls (Redis MGET only): 80k events/s ×
1 MGET ≈ 80k Redis ops/s (one Redis sustains ~100k+; shard by city). Firm `/v1/quotes` happen **only
at checkout** (~1.6k orders/s, not 80k) × ≤4 legs × ≤5% miss ≈ a **few hundred firm quotes/s** to
shipping-service. CPU search is microseconds; the floor is PHP-FPM request overhead (~ms) →
~hundreds of `optimizeCart`/s per worker → a deferred 80k/s browse fleet ≈ **200–400 stateless
workers**. **Today** (checkout-only, tens/s) → existing FPM workers suffice. This is precisely why
the event bus + fleet + WebSocket push are deferred.

**Failure modes & mitigations:**
- shipping-service slow/down → estimate fallback + `quote_source` flag; never blocks the user.
- Redis down → quote/serviceability caches become best-effort; estimator still works from the DB.
- Stale cart memo after a stock event → `verify` re-runs assignment server-authoritatively.
- Unfulfillable line → excluded from totals, surfaced like today's `checkoutEstimate`.
- Missing weight/dims → `CourierService::defaultPackage()` fallbacks; flagged via `quote_source`.

**Deferred (documented, not built):** Kafka/Redis-Streams cart-event bus; stateless worker fleet
(50–80k/s); WebSocket/SSE live push of firm-quote upgrades. **Extraction path:**
`ShippingQuoteClientInterface` + `CandidateProviderInterface` become RPC clients to a Go/Python
worker; `CostModel` / `GreedyAssigner` / `TwoOptRefiner` lift wholesale behind the same contracts.

---

## 13. Integration & rollout (additive, reversible)

- `checkoutEstimate` keeps its existing `items[]`/`totals` **exactly**; behind `deliveryoptimizer.enabled`
  (and/or `?consolidate=1`) it **adds** a top-level `optimized` block. Two concrete wins over today:
  (a) route the existing per-courier-line `CourierService::serviceability()` call (currently live +
  uncached) through `RedisQuoteCache`; (b) replace the duplicate-fee `Σ shipping_total` with
  `customer_flat_fee` (flag-gated).
- `verify` later derives `shipping_charge` from the optimizer (firm), honouring the same
  free-delivery short-circuit, wrapped in try/catch → falls back to `calculateShippingCharge`.
- No route changes; `throttle:60,1` + 50-item cap stay. Everything is flag-guarded and reversible.

---

## 14. Verification

- **Unit** (in-memory fakes, no DB): bucketing; `CostModel` delivery-once dedup; greedy/2-opt
  consolidation; incremental add/remove gating; estimate fallback never throws; **the locked
  `ConsolidationScenarioTest`** (exactly 4 shipments, S+T consolidated on D, one flat fee,
  `internal_true_cost` = 4 fees).
- **Feature** `CheckoutEstimateOptimizerTest`: seed products/types/`vendor_*`; `POST /checkout/estimate`
  with the 8-line cart → legacy response intact **and** `optimized.shipments` = 4 legs + one flat fee.
- **Load harness** (`tests/Load/optimizer_loadtest.php`): randomized carts, cold/warm cache → p50/p95/
  p99 + cache-hit-rate + firm-vs-estimate ratio; gate **p99 < 150ms** warm.
