# PlantAtHome — Architecture Audit Findings (2026-08-10)

Every finding is evidence-backed (file:line at audit time). Status reflects this remediation pass.
P0 = security/financial/data-integrity · P1 = reliability/scale · P2 = maintainability · P3 = polish.

## P0 — fixed this pass

| ID | Finding | Root cause | Fix | Test |
|---|---|---|---|---|
| F-1 | Duplicate `POST /orders` created duplicate orders, duplicate PSP intents, double stock deduction, double wallet debit | No idempotency mechanism; endpoint also unthrottled; identity minted server-side per request | `Idempotency-Key` header + unique `orders.idempotency_key` (pre-check + race catch return the original); `throttle:20,1`; storefront sends a per-attempt UUID | `DuplicateCheckoutTest` (6) |
| F-2 | The oversell "gate" was dead code — `CheckoutRepository::isInStock` stubbed to constant "in stock", so `checkStock()` always `[]`; a 0-row decrement only logged | Deliberate policy drift ("city is the only gate") contradicting the H8 guard comment | Atomic decrement is now the authoritative gate: `INVENTORY_OVERSELL_POLICY=block` ⇒ `InsufficientStockException` (422) inside the order txn, full rollback; `log` preserves legacy behavior until per-env data check | `InventoryConcurrencyTest` (5, incl. qty=1×100 ⇒ exactly 1) |
| F-3 | 100%-wallet orders never decremented stock (early `return` before `OrderProcessed`) while cancelling them restored — counters inflate | Early-return path skipped the event | `event(new OrderProcessed($order))` before the return | covered by decrement suite + review |
| F-4 | `payment.failed` webhook left orders PENDING forever with stock deducted + coupon consumed (deliberately retry-friendly, but nothing ever timed out) | No expiry mechanism | `orders:cancel-stale-unpaid` (15-min schedule): >24h unpaid prepaid parents → standard cancel machinery (idempotent restock + coupon release); COD (3 spellings)/wallet/paid/young/shipped exempt | `StaleUnpaidSweepTest` (4) |
| F-5 | 11 unauthenticated, unthrottled gateway webhook routes — all aliases into the ACTIVE gateway handler; 9 gateways unused | Template shipped every gateway wired | `WEBHOOK_GATEWAYS` allowlist (404 otherwise; razorpay fails closed without its secret) + `throttle:120,1` on all 12 routes; bare `exit()`s → `abort(400)`/normal return | `RazorpayWebhookTest` (5) |

## P1 — fixed this pass

| ID | Finding | Fix |
|---|---|---|
| F-6 | Prod `SESSION_DRIVER` unset → file sessions = instance-local state (the one missed env in `production.yml`'s block) | `SESSION_DRIVER=redis` in the prod env block |
| F-7 | Razorpay intent (network call) created INSIDE the order txn — PSP latency held coupon/wallet row locks | Intent moved to `OrderController::store` after commit |
| F-8 | `generateTrackingNumber`: day-wide pluck (O(n)/order), stale snapshot, 900k/day space | Per-attempt `exists()` probe, 90M/day space, unique index as arbiter |
| F-9 | Queued order listeners dispatched inside the open txn (worker could beat the commit) | `after_commit=true` on the redis queue connection (database already had it) |
| F-10 | Unthrottled public surfaces: `/logout`, `coupons/verify` (enumeration), token-URL downloads ×3, `license-key/verify`, `shop-maintenance-event`, v1 search | Throttles added to each; `routes/api.php` `/user` moved `auth:api`→`auth:sanctum` |
| F-11 | No payment-webhook or marvel order/inventory tests existed (all concurrency tests targeted the unused v1 stack) | Order/Payment/Inventory suites added (20 new tests) |

## P1 — open (operator / infra; in the runbook)

| ID | Finding | Why open |
|---|---|---|
| F-O1 | Razorpay prod key is still `rzp_test_` — the live money path has never executed | Operator secret |
| F-O2 | Redis is on-box (`127.0.0.1`) — cache, rate limits and now sessions are per-instance the moment a 2nd box exists | Managed Redis (ElastiCache) is an infra purchase |
| F-O3 | Deployed plant-doctor ≠ repo source (H7) — a blind redeploy would crash it | Needs operator reconciliation window (docs/operations has the recipe) |
| F-O4 | Single 2-core origin, ~122 RPS ceiling, no LB | Infra sizing decision |
| F-O5 | Staging config diverges (cache=file vs prod redis) — can't reproduce cache-coherence bugs | Railway env change |

## P2 — documented, deferred to [modernization-roadmap.md](modernization-roadmap.md)

- **F-12** Stock split across 3 tables (`products.quantity`, `variation_options.quantity`,
  `vendor_product_prices`) with a derived 4th (`product_city_availability.stock`); vendor
  reservation subsystem exists but is flag-gated OFF and assigns with `reserved_qty=0` on failure.
- **F-13** Giant legacy files: ProductController 1,747 · Routes.php 1,349 · UserController 1,255 ·
  OrderRepository 1,014 · ProductRepository 1,008; 22/92 controllers >300 lines (`app/Modules`: zero).
- **F-14** Storefront still on the marvel order stack while the v1 Sales stack (idempotency,
  reservations, tests) sits unused — the single largest consolidation opportunity.
- **F-15** Admin giants: shop-form 1,750 · settings-form 1,104 · product-form 1,052; duplicate
  `form/` + `forms/` primitives; admin on real react-query v3 vs shop on v5-behind-a-shim.
- **F-16** `design-system.ts` manually duplicated across shop+admin ("keep identical" comment) — no
  package boundary.
- **F-17** Three diverging "current state" records (md audit 08-07, admin TS dataset 08-09, perf
  JSON) — [security-findings.md](../security/security-findings.md) now declares the canonical.
- **F-18** No Horizon / queue dashboard; worker definitions hand-synced between systemd template and
  supervisord; code defaults unsafe (`queue=sync`, `session=file`, `cache=file`) — a bare instance
  comes up silently degraded and `/health/ready` wouldn't notice (probe gap).
- **F-19** `admin/graphql` workspace is dead (pre-migration snapshot, vulnerable deps) — delete.
- **F-20** `products.quantity` is a SIGNED integer (variants are unsigned) — only the conditional
  WHERE prevents negatives from other write paths; needs a data check + migration.
- **F-21** `expandToInventoryUnits` hardcodes `variation_option_id=null` — bundle components can't
  be variant-specific.

## P3

Stale `PRODUCTION_READINESS.md` (2026-06, RBAC-scoped) superseded by
[docs/operations/production-readiness.md](../operations/production-readiness.md); `architecture.html`
(51KB, hand-authored, build-frozen) should gain a generator or defer to these docs; empty README.
