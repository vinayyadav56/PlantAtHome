# PlantAtHome — Delivery / Logistics Integration Audit (2026-08-10)

Evidence-based audit of what delivery/logistics is *actually* implemented — traced through code
(three cross-validated read-only passes + working-tree checks), not inferred from names, env vars,
tables, or comments. Sections 1–23 are the factual baseline; 24–26 the recommended plan.

> **Implementation status.** The P0 gap this audit found — the courier subsystem is code-complete
> but *never triggered by the live order flow* (booking was manual-only) — plus the P1 follow-ups
> are now closed in code and tested. **547 tests green (+13).**
>
> Shipped:
> - **Auto-booking** (`Marvel\Listeners\BookCourierShipments`) on `OrderProcessed` (COD/full-wallet)
>   and `PaymentSuccess` (prepaid), queued/after-commit, idempotent per shipment, retry→failed_jobs.
> - **Undispatched-shipment alarm** (`courier:sweep-undispatched`, 15 min) + stale Kernel comment fixed.
> - **Margin watch** (`courier:margin-report`, daily) — orders where Σ `booked_cost` > charged
>   `delivery_fee`. Report-only; dormant until bookings exist.
> - **Mobile push** — `device_tokens` table + `POST /api/device-tokens` + `ExpoPushService`
>   (credential-free) wired into `SendOrderStatusChangedNotification` / `SendOrderDeliveredNotification`;
>   mobile registers its Expo token on sign-in.
> - **Mobile checkout** now shows the server-authoritative delivery fee (drops the ₹49/₹999 client
>   guess; the charge was always server-side, this fixes the display honesty).
> - Tests: `AutoBookShipmentsTest` (7), `ExpoPushTest` (4), `MarginReportTest` (2). Courier auto-book
>   + push are **no-ops until courier / a registered device exist** (courier still off by default).
>
> Deliberately NOT done: **Phase 0 enablement** (env keys + DB master switch — operator/secrets),
> **secret hygiene** (plaintext creds left untouched per owner instruction), **Porter-COD** (reverses
> a documented 2026-08-03 business decision — needs an explicit owner call), **mobile PDP pincode
> checker** (skipped — the app is city-gated by design, a pincode checker would be redundant), and
> **distinct booked/picked-up/RTO customer notifications** (folded into the generic status push for now).
> See §25–26.

---

## 1. Executive Summary

A **real, non-mock, multi-provider logistics stack exists and is code-complete end-to-end**: a Go
microservice (`/Users/vinayyadav/PlantAtHome/shipping-service`, standalone git repo, deployed on
**Railway**) with genuine API adapters for **Borzo, Shiprocket, Porter** (quote / book / cancel /
track / webhook / COD), fronted by the Laravel monolith (`packages/marvel`) via `ShippingServiceClient`
+ `CourierService`, with a live inbound status webhook (`POST /shipping/callback`) and operator UI on
all three surfaces (web / admin / mobile).

Before this pass it was **not wired into the live order lifecycle**. In the checked-in configuration
it is dormant:
- `api/.env` has **no `SHIPPING_SERVICE_*` keys** (verified) → `ShippingServiceClient::configured()`
  false → every courier op returns a structured "not configured" failure. (An api_key *could* be
  injected at runtime via the admin `courier_partner_configs` DB row — not verifiable from disk.)
- DB master switch `settings.options.courier.enabled` gates everything, defaults false.
- **No auto-booking** (before this pass): `OrderProcessed => [ProductInventoryDecrement]` only. A
  courier was contacted **only** by the manual admin "Dispatch" route.

The **live delivery charge** is the legacy server-computed per-product `delivery_charge` sum; the
courier *quote* only feeds the PDP "check delivery" ETA and degrades to a static "3–7 days" string
when unconfigured (the default). Checkout's serviceability gate is the **city gate**, not courier
serviceability.

**NOT integrated (zero code anywhere):** Rapido, Delhivery, Blue Dart, DTDC, XpressBees, Shadowfax.
`Porter/` and `Shiprocket/` at repo root are **scratch/credential dirs, not services** (plaintext keys).

## 2. Delivery Providers Found

| Provider | Actual Status | Evidence |
|---|---|---|
| **Borzo** | Code-complete E2E, runtime-GATED (off) | `shipping-service/internal/partner/borzo.go` (calculate-order/create-order/cancel/track + HMAC webhook) |
| **Shiprocket** | Code-complete E2E, runtime-GATED (off) | `shipping-service/internal/partner/shiprocket.go` (auth/serviceability/AWB/label/pickup/track/webhook) |
| **Porter** | Code-complete E2E, runtime-GATED (off); COD nominal | `shipping-service/internal/partner/porter.go`; COD has no collect field (`porter.go:40-47`) yet descriptor defaults COD=true (`:117`) |
| **Go shipping-service ↔ Laravel link** | PARTIALLY INTEGRATED / configured-off | `ShippingServiceClient.php`, `CourierService.php:106`, `WebHookController.php:107` |
| **Delivery Optimizer / FirmQuoteClient** | PARTIAL, flag-gated OFF | `config/deliveryoptimizer.php:10`; `CheckoutRepository.php:412-438` (metadata only, never changes total) |
| **`integration_providers` + AES-GCM cred sync** | CONFIGURED-ONLY (off) | migration `2026_07_29_000100`; `Integrations/CredentialSync.php`, `Sealer.php` |
| Rapido, Delhivery, Blue Dart, DTDC, XpressBees, Shadowfax | NOT-INTEGRATED | no code in the tree |
| Internal `delivery_partners` (riders) | Separate system (payout+KYC+GPS), not a courier API | migrations `2026_06_13_120000*`; `DeliveryPartnerController` |

## 3. Current Delivery Architecture (default deployment)

```
              CUSTOMER (web / mobile)
                     │
                     ▼
       CHECKOUT ── city-gate (LIVE) ── 422 if city mismatch / out-of-stock / vertical off
                     │        (pincode courier-serviceability gate EXISTS but flag-OFF, verify-preview only)
                     ▼
        POST /api/orders  (LEGACY marvel stack — the LIVE path)
                     │
        OrderRepository::storeOrder
          ├─ delivery_fee = Σ product.delivery_charge   (free-ship first; optimizer OFF)  ← LIVE charge
          ├─ child orders per vertical (parent_id)       ← split #1
          └─ OrderItemService: Shipment rows per (shop × fulfillment_mode), status='pending'  ← split #2
                     │
                     ▼
          event(OrderProcessed) → [ProductInventoryDecrement, BookCourierShipments*]
                     │                                         (*NEW: COD/full-wallet book here;
                     │                                           prepaid books on PaymentSuccess)
                     ▼
        CourierService::book → ShippingServiceClient → Go shipping-service → Borzo/Shiprocket/Porter
                     │                                          │
              AWB / tracking_url on shipment          transactional outbox (2s) + reconcileLoop (10min)
                     │                                          │
                     ▼                                          ▼
        POST /api/shipping/callback (x-api-key, hash_equals)  ◄── partner webhook → Go svc
                     │
        applyNormalizedStatus → changeOrderStatus (monotonic) → OrderStatusChanged/OrderDelivered
                     │
              email + SMS notifications  (NO mobile push)

        courier:sweep-undispatched (15min)* — alarm on legs left unbooked past SLA (NEW)
```

## 4. Actual Order → Delivery Flow

- Live entry `OrderController::store` (`OrderController.php:200`) → `DB::transaction` →
  `OrderRepository::storeOrder` (`:175`). `OrderProcessed` fires on the **parent** order at `:427`
  (normal) and `:397` (full-wallet).
- `app/Modules/Sales` `/api/v1` stack is not the storefront path and hardcodes `delivery_fee=0`
  (`CheckoutService.php:219`).
- Local `Shipment` rows created synchronously in `OrderItemService::applyAssignment` →
  `Shipment::create` (`OrderItemService.php:233-241`), status `pending`, in a try/catch.
- Provider booking: previously manual (`CourierShipmentController::book`, `Rest/Routes.php:916`); now
  ALSO automatic via `BookCourierShipments` (this pass).

## 5. Multi-Vendor / Sub-Order Flow

Two parallel splits, both real: **child orders by vertical** (`createChildOrder`,
`OrderRepository.php:800-863`, fees apportioned by subtotal share) and **shipments by vendor × mode**
(`OrderItemService::assignAndGroup`, `OrderItemService.php:225-320`, each with own `eta_days`,
`shipping_cost`, `status`, and post-booking `awb_number`/`provider`/`tracking_url`). One customer
order → many child orders AND many shipments. Order completes only when no shipment is in-flight
(`CourierService.php:409-413`). Correct multi-vendor modelling.

## 6. Provider Selection Logic

Mode split `local | courier` is per-line `fulfillment_mode`. Which **partner** fulfils a courier
shipment is decided **inside the Go service** at book/quote time (cheapest/capacity-aware), plus admin
`mode_priority` / per-partner enable in `courier_partner_configs`. **No PHP-side rules engine** keyed
on weight/distance/SLA/category — selection is service-side + admin config.

## 7. Serviceability Flow

- **City gate (LIVE):** `shoppingCityMismatch` / `shoppingCityOutOfStock` / `assertVerticalsAvailable`
  in `storeOrder` (`OrderRepository.php:195-227`) via `AvailabilityService`.
- **Pincode courier-coverage gate: NOT LIVE.** `CheckoutRepository::applyCoverageGate` runs only in
  the advisory `verify` preview (`:114`), flag-gated `coverageCheckoutGate` (off), fails open. In
  `storeOrder`, pincode coverage is an audit-only snapshot ("never gates", `OrderRepository.php:305`).
- PDP `GET /delivery-options` calls a live courier quote but degrades to a static estimate when
  unconfigured (the default).

## 8. Shipping Price Calculation

Server-authoritative in `storeOrder` (`OrderRepository.php:350-373`): (1) free-shipping (coupon or
`options.freeShipping` threshold) → 0; (2) optimizer flat fee (dormant, flag off → null); (3) legacy
per-product `Σ qty × product.delivery_charge` (the live calc, `CheckoutRepository:548-560`), fallback
to admin `shippingClass` slab. Shown fee == persisted fee (same code path in `verify` and `storeOrder`).
⚠️ shipment-level `shipping_cost` (a courier **cost** estimate) is **not reconciled** against the
customer-charged `delivery_fee` — margin can silently diverge once courier is on (§25 Phase 2).

## 9. Shipment Creation

Local rows synchronous at order time (§4). Provider booking: `CourierService::book` →
`ShippingServiceClient::book` → `POST /v1/shipments` (`ShippingServiceClient.php:259`), gated on
`shippingServiceEnabled()` (master switch AND configured). On success it persists
`provider_order_id`/`awb_number`/`status='assigned'` (`:267-280`); on failure sets
`last_status='book_failed'` and leaves status non-terminal (so it stays retriable). Idempotent: Porter
deterministic `request_id`, Shiprocket returns provider ids even on AWB failure.

## 10. Tracking & Status Synchronization

`POST /api/shipping/callback` (`WebHookController::shippingCallback:107`) — `x-api-key` via
`hash_equals`, gated on courier-enabled, `applyNormalizedStatus` → monotonic shipment status →
`changeOrderStatus` when no leg in-flight. Go-side backstop: transactional outbox (2s) + `reconcileLoop`
(10min) re-`Track()`s open bookings. The Laravel Kernel's re-track comment was **stale** (no scheduled
command) — replaced this pass with `courier:sweep-undispatched`; status recovery for BOOKED shipments
correctly lives in the Go reconcileLoop.

## 11. Webhooks

Partner → Go `POST /webhooks/{partner}` (per-partner signature verified: Borzo HMAC-SHA256 over the raw
body in `X-DV-Signature`, keyed by the cabinet's Callback Secret Key — their documented contract;
Shiprocket + Porter shared-token; constant-time; idempotent; always ACK 200; audited to `webhook_logs`)
→ outbox → Laravel `/shipping/callback` (x-api-key). Distinct from payment webhooks.

Anti-spoof, precisely: an unverified callback is **dropped** at `handlers.go` (`HandleWebhook` is never
called), so a forged one cannot move state. The authenticated `Track()` re-fetch is a *second* defence
against a signed-but-lying body — it does not run as a fallback for a bad signature. A rejected callback
therefore costs latency, not correctness: status still lands via the 10-minute reconcile sweep.

## 12. Database Structure

```
Order ─┬─ children Order (parent_id, per vertical)
       ├─ OrderItem (assigned_shop_id, shipment_id, fulfillment_mode, eta_days, margin)
       │      └─ Shipment (shop_id, fulfillment_mode, status, awb_number, provider,
       │                   provider_order_id, tracking_url, shipping_cost/revenue,
       │                   booked_cost/revenue, cod_amount, shipped_at/delivered_at)
       │              └─ DeliveryQuote (partner, pickup/drop pincode, weight_g, quoted_cost/eta, expires_at)
       └─ Payment
CourierPartnerConfig (partner_code, enabled, settings, credentials[encrypted])
integration_providers (provider_slug, category, environment, credentials[AES-GCM], health/sync status)
Serviceability v2: postal_codes, svc_pincodes, vendor_coverage_rules, vendor_covered_pincodes,
                   vendor_service_areas, coverage_audit_logs, delivery_notify_requests
Internal riders: delivery_partners (+balances/earnings/withdraws) — NOT a courier API
Legacy: delivery_pincodes, delivery_times, products.delivery_charge (live charge source)
```
Migrations: `shipments` `2026_06_20_100010` (+courier cols `_23`, cost cols `_25`); `order_items`
`2026_06_20_100000`; `courier_partner_configs` `2026_06_28_100000`; `integration_providers`
`2026_07_29_000100`. All FKs cascade from `order_id`.

## 13. Backend APIs

| Method | Endpoint | Controller | Auth/Throttle |
|---|---|---|---|
| GET | `delivery-options` | `DeliveryOptionsController@check` | throttle; live courier quote w/ static fallback |
| GET | `delivery-pincodes/check` | `DeliveryPincodeController@check` | throttle |
| POST | `delivery-notify` | `DeliveryNotifyController@store` | throttle |
| GET | `orders/{tracking}/shipments` | `OrderAssignmentController@trackingShipments` | throttle:60,1, owner/token |
| GET | `orders/{tracking}/courier-location` | `OrderAssignmentController@courierLocation` | throttle:120,1 |
| POST | `shipping/callback` | `WebHookController@shippingCallback` | x-api-key `hash_equals` |
| POST | `shipments/{id}/{book-courier,dispatch,generate-label,schedule-pickup,courier-track,cancel-shipment,mark-rto}` | `CourierShipmentController` | auth+permission — manual ops |
| GET/POST | `courier-settings`, `courier/partners/{code}/config\|token/refresh\|webhooks\|test/*` | `CourierConfigController`/`CourierPartnerProxyController` | auth+permission |
| GET/PUT/POST | `integrations`, `.../test`, `.../sync` | `IntegrationController` | `permission:settings.integrations.*` |

## 14. Queue / Events / Jobs

- Order events: `OrderCreated`, `OrderReceived` (per child), `OrderProcessed`, `PaymentSuccess`,
  `PaymentFailed`, `OrderStatusChanged`, `OrderDelivered`, `OrderCancelled`.
- Listeners: `OrderProcessed → [ProductInventoryDecrement, BookCourierShipments]`;
  `PaymentSuccess → [SendPaymentSuccessNotification, BookCourierShipments]`;
  `OrderDelivered → [SendOrderDeliveredNotification, GenerateCarePlanOnDelivery]`;
  `OrderStatusChanged → [SendOrderStatusChangedNotification]`.
- Go service async: outbox relay (2s), reconcileLoop (10min), COD import/settlement.
- Scheduled: `courier:sweep-undispatched` (15min, this pass).

## 15. Admin Delivery Features

IMPLEMENTED & wired: provider config + write-only credentials + enable/disable; per-partner runtime
toggles + Porter cost switches; Shiprocket token refresh; integration console (live test
quote/track/book/cancel + webhook log); per-shipment ops (quote/dispatch/label/pickup/track/cancel/
mode-override/mark-RTO); Command Center live delivery ops + courier-position map (8–10s polling);
delivery-partner (rider) onboarding/KYC/approve; city/pincode serviceability + vendor coverage rules +
warehouse pickup sync. PARTIAL/vestigial: legacy flat-rate `shippings` CRUD — not the live charge source.

## 16. Web Customer Flow

IMPLEMENTED: PDP pincode checker → live `delivery-options` quote (`delivery-check.tsx:51`,
`source: live|vendor|estimate`); checkout server-side shipping via `/checkout/estimate` + authoritative
`/orders/checkout/verify` (`place-order-action.tsx:122`); order tracking renders real per-parcel
`TrackingStepper` + `ParcelShipments` from `/orders/{tracking}/shipments`. Cart: no shipping calc.
Local same-city ETA is a deliberate "1 day" rule.

## 17. Mobile Customer Flow

Same backend endpoints (`config.ts:90-92`). IMPLEMENTED: order tracking + live courier map
(`order/[tracking].tsx`). DIVERGENCES/GAPS: (a) checkout shipping falls back to a **client-side**
`₹49 / free ≥₹999` rule and does **not** call `/checkout/verify` (`app/checkout.tsx:269-278`);
(b) **no PDP live-courier pincode checker** (uses city-gating); (c) **no delivery push notifications**
— `src/lib/notifications.ts` schedules only local care reminders, no Expo push token / device
registration.

## 18. Security Findings

- ✅ Callback + partner webhooks use constant-time signature/key checks; idempotent; secrets
  header-only, never logged. Credentials at rest AES-256-GCM.
- 🔴 **Plaintext provider credentials in the working tree**: `Porter/SilvestrixTesting UAT
  Collaterals/*`, `Shiprocket/shiprocket_api_key.json` (+ repo-root `creds.json`,
  `replicate_creds.json`). Gitignored but present in cleartext — rotate + remove.
- ⚠️ `SHIPPING_SERVICE_ENABLED` env is vestigial (only in a comment); real switch is a DB setting.
- ⚠️ Porter COD is nominal (no collect-amount field) — advertising Porter COD could promise cash
  collection that never happens.

## 19. Failure & Edge Cases

| Scenario | Handled? |
|---|---|
| Provider API fails / times out | ✅ (25s timeout; structured failure; order unaffected) |
| Serviceability fails | ✅ fail-open |
| Duplicate shipment request | ✅ (Porter request_id; Shiprocket ids-on-fail; **auto-book idempotency guard**) |
| Webhook arrives twice / before shipment / missed | ✅ (idempotent handler + outbox idempotency-key + reconcileLoop) |
| Payment ok but shipment book fails | ✅ now (auto-book retry→failed_jobs; sweep alarm) |
| Order placed, never dispatched | ✅ now (auto-book + sweep alarm) |
| Customer/vendor cancel, RTO | ⚠️ partial (`mark-rto`/cancel exist; no distinct customer "returned" notification) |
| Fee charged ≠ courier cost | 🔴 not reconciled (§25 Phase 2) |

## 20. Working Features

Multi-vendor child orders + per-vendor shipments; server-side delivery-fee; city serviceability gate;
Go service with real Borzo/Shiprocket/Porter adapters; live status callback → order advance; web PDP
live ETA + server-verified checkout + real tracking UI; full admin courier ops console; email+SMS
status notifications; COD reconciliation/settlement; encrypted creds + AES-GCM sync; **auto-booking +
undispatched-shipment alarm (this pass)**.

## 21. Partial Features

Optimizer/firm-quote (off, metadata-only); pincode coverage gate (off, verify-only); Porter COD
(nominal); mobile checkout shipping (client-side fallback, no verify); "shipment created"/"picked
up"/"returned" folded into generic status; legacy `shippings` flat-rate CRUD (vestigial).

## 22. Missing Features

Mobile push for delivery events; mobile PDP delivery checker + `/checkout/verify` parity; fee-vs-cost
margin reconciliation; distinct booked/picked-up/returned customer notifications; a PHP-side
provider-selection rules engine (only if business needs weight/SLA rules beyond service-side cheapest).

## 23. P0/P1/P2/P3 Gaps

- **P0 — CLOSED (code, this pass):** no auto-booking → orders silently stuck at `pending`; no
  undispatched-shipment alarm.
- **P0 — operator:** courier subsystem OFF (`SHIPPING_SERVICE_API_KEY` absent, master switch off).
- **P1:** fee charged vs courier cost not reconciled; mobile no push / client-side fallback / no
  `/checkout/verify`; Porter COD nominal.
- **P2:** plaintext creds in working tree; vestigial `SHIPPING_SERVICE_ENABLED` doc trap; distinct
  RTO/returned notification.
- **P3:** provider-selection rules engine; retire legacy `shippings`/`delivery_pincodes`; consolidate
  the two order stacks.

## 24. Recommended Production Architecture

Keep the current shape — a **modular monolith + one Go logistics service** is right; do not add more
services. Make booking event-driven, gated, idempotent, observable (done this pass), then enable it
per-environment and add margin reconciliation + mobile parity.

## 25. Implementation Plan — status

**Phase 0 — Enablement & safety (OPERATOR).** Set `SHIPPING_SERVICE_URL/API_KEY/CALLBACK_KEY` on the
API env; enable partners in `courier_partner_configs`; flip `settings.options.courier.enabled` on
**staging first**. Remove plaintext creds from the working tree and rotate. Do NOT offer Porter-COD
until §Porter resolved. *(Not automatable from code — secrets/DB/ops.)*

**Phase 1 — Auto-booking. ✅ DONE (this pass).** `BookCourierShipments` queued listener on
`OrderProcessed` (COD/full-wallet) + `PaymentSuccess` (prepaid), gated by `shippingServiceEnabled()`,
idempotent per shipment (skip legs with `provider_order_id`/`awb_number`), retry-with-backoff →
failed_jobs. Reuses `CourierService::book` — no new booking path. Tests: `AutoBookShipmentsTest`.

**Phase 2 — Observability. ✅ sweep DONE (this pass); reconciliation PENDING.**
`courier:sweep-undispatched` (15min, `withoutOverlapping`) alarms on shipments unbooked past
`shop.undispatched_shipment_alert_minutes` (default 120); stale Kernel comment replaced. PENDING:
reconcile `delivery_fee` (charged) vs shipment `booked_cost` for margin reporting (report only).

**Phase 3 — Mobile parity (FOLLOW-UP, separate `plantathome-app` repo).** Expo push-token registration
+ device-token endpoint; subscribe delivery events to push; make mobile checkout call `/checkout/verify`
and drop the client-side `₹49/₹999` fallback; add the PDP `/delivery-options` checker.

**Phase 4 — Correctness tails (FOLLOW-UP + BUSINESS DECISION).** Distinct customer notifications for
booked / picked-up / returned(RTO). Resolve Porter COD: either a real collect mechanism or hard-disable
Porter-COD in the descriptor — this reverses a documented 2026-08-03 business decision, so it needs
owner sign-off, not a silent flip.

**Deferred (P3):** provider-selection rules engine, retire legacy `shippings`/`delivery_pincodes`,
consolidate the two order stacks.

**Guardrails:** every phase staging-first; auto-booking landed with tests that a duplicate event books
once and that a booking failure does not roll back a paid order; no destructive prod testing; no
secrets in code or logs.

## 26. Exact Files Changed / To Change

**Changed this pass:**
- `packages/marvel/src/Listeners/BookCourierShipments.php` (new) — auto-book listener.
- `packages/marvel/src/Providers/EventServiceProvider.php` — register on `OrderProcessed` + `PaymentSuccess`.
- `app/Console/Commands/SweepUndispatchedShipmentsCommand.php` (new) — `courier:sweep-undispatched`.
- `app/Console/Kernel.php` — schedule the sweep; replace the stale re-track comment.
- `packages/marvel/config/shop.php` — `undispatched_shipment_alert_minutes`.
- `tests/Feature/Courier/AutoBookShipmentsTest.php` (new) — 7 tests.

**To change (follow-up):**
- `plantathome-app/app/checkout.tsx` + `src/lib/notifications.ts` (+ device-token endpoint) — mobile
  `/checkout/verify` + Expo push. (P1)
- `shipping-service/internal/partner/porter.go` — Porter-COD resolution. (P1, business decision)
- Working tree `Porter/`, `Shiprocket/`, `creds.json`, `replicate_creds.json` — remove + rotate. (P2)
- A margin-reconciliation report (`delivery_fee` vs `booked_cost`). (P1)

## Verification

- Auto-booking: `AutoBookShipmentsTest` — duplicate event books once (idempotent); booking failure
  throws (retry) without touching the committed order; prepaid waits for `PaymentSuccess`; courier-off
  is a no-op. Full suite **541 tests green**.
- Enablement (when done): on staging with keys + master switch on, place a test order → a `Shipment`
  gets `awb_number`/`provider_order_id`; POST a signed `/shipping/callback` → order status advances +
  email/SMS fire.
- Sweep: seed a `pending` shipment older than SLA → alarm fires once (covered by test).
