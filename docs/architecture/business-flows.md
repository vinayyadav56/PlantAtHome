# PlantAtHome — Business Flows (traced end-to-end, 2026-08-10)

Every step names the actual code. Marvel paths are the LIVE storefront path; `/api/v1` equivalents
noted where they exist.

## Customer: browse → buy

| Step | UI | API / code | Consistency & failure notes |
|---|---|---|---|
| City selection | `CityPickerDialog`, `pah_customer_city` localStorage, `useCustomerCity` (useSyncExternalStore) | city rides as `?city=` on every catalog call | 422 city gates use HttpResponseException; blocking gates must never trap (app-city-gate lesson) |
| Catalog | listing `/plants/search`, home rails | `GET /products` (`ProductController::index` → `fetchProducts`, version-keyed cache + stampede lock) · feeds `popular/best-selling/top-rated` | ALL feeds run `overlayCityPrices` — one price source; anonymous defaults `status:publish` |
| PDP | `plantathome-details.tsx` | `GET /products/{slug}?city=` → `attachCityPricing` (per-variant city prices, `city_available` flags) + `attachAvailability` | size options hidden when `city_available=false` (fail-open); `useCityPrice` = the ONE price resolver |
| Availability | delivery card + pincode check | `location-price`, `checkout/estimate`, serviceability endpoints | vendor coverage per (shop, city, pincode); fail-open by design |
| Cart | client cart context | — (client state; server re-verifies everything) | frontend price is display-only |
| Coupon | checkout preview | `POST coupons/verify` (throttled) — advisory | authoritative validation re-runs at order creation (approval/window/min-cart/target/exhausted) |
| Checkout verify | review step | `POST orders/checkout/verify` (`CheckoutRepository::verify`) | ADVISORY ONLY — no reservation; storeOrder recomputes everything |
| **Order create** | `place-order-action.tsx` (sends `Idempotency-Key` UUID per attempt) | `POST /orders` (throttle 20/min) → `OrderController::store` → `DB::transaction(storeOrder)` | **Idempotent** (unique `orders.idempotency_key`, pre-check + race catch → original order returned). Inside the txn: city/vertical gates → server-recomputed discount/fee/tax → `createOrder` → child orders per vertical → `consumeCouponIfAny` (row lock, UNIQUE ledger) → wallet lock/debit → **atomic stock decrement** (policy `block` ⇒ 422 rollback on shortfall). PSP intent created AFTER commit (kept payable on PSP failure) |
| Payment | Razorpay checkout / Pay Now | `POST orders/payment` (throttle) → `submitPayment`; webhook `POST webhooks/razorpay` (throttled, allowlisted, signature + amount verified) | webhook replays idempotent (state guard); `payment.failed` keeps order payable; **stale-unpaid sweep** cancels+restocks+releases coupon after 24h |
| Split orders | — | `createChildOrder`: one suborder per vertical, pro-rated tax/fee/discount, same transaction | `OrderItemService::writeForOrder` (nested txn, completeness-idempotent) + auto-assignment |
| Delivery | tracking page `/track-order` | Go shipping-service books Borzo/Shiprocket/Porter; status returns ONLY via `shipping/callback` (token-verified, idempotent, never-5xx) | monotonic status advance via `CourierService::applyNormalizedStatus` |
| Refund/Return | account refunds | `RefundController` (row-locked, restocks once per child via `inventory_restored` guard) | double-refund rejected (also covered in v1 SalesFlowTest) |

## Vendor (nursery)

Onboarding → approval (`approve-shop` rewrites commissions; store_owner ROLE required, bare perms
403) → KYC gate (hold engine + `sweep-kyc-deadlines`) → product parity/catalog attach
(`add-from-catalog`) → per-size vendor prices (`vendor_product_prices`, dedupe key, `track_stock`) →
availability projection recompute (`AvailabilityService::recomputeForProduct` on every
inventory/price write; nightly city-wide recompute) → orders visible via assignment
(`OrderItemService::assignAndGroup`, cheapest covering rate) → fulfilment → settlements
(`marvel:run-settlements` 04:00, vendor ledger UNIQUE per order, COD remittance matcher). Vendor
isolation enforced by RBAC scoping (tested: `RbacTest` — 4× 403).

## Admin

Catalog/bundles (derived bundle stock), vendor approval + KYC, city command center
(cities/verticals kill-switches — fail-open), pricing margins (flat-₹, `MarginResolver::apply` only),
inventory, order board (Kanban + assignment), coupons (usage limits — admin form),
payments/settlements, users+RBAC (designations, 96 perms), reports (performance/security evidence
pages), Mission Control + NOC, marketing automation (dedicated `marketing` worker queue).

## Auth flows

- Phone OTP: `send-otp-code` → `OtpAbuseGuard.canSend` (45s cooldown, 8/24h cap) → MSG91 →
  `verifyOtpCode` (5-fail → 15min lockout) — all on top of phone-keyed `throttle:otp`.
- Email+password (`/token`, throttle 5-10/min), social (Google/LinkedIn), guest checkout
  (RAZORPAY forced; per-order `tracking_token` secret for guest order pages).
- Logout (`/logout` token-revoke, now throttled) / `logout-all-devices` (authed) for stolen sessions.

## Where each business rule is authoritative (frontend never decides)

| Rule | Owner |
|---|---|
| Price (incl. city) | `PricingService` + `attachCityPricing`/`overlayCityPrices` — server only |
| Discount | `storeOrder` recomputes; clamped to subtotal; client `discount` ignored |
| Delivery fee + tax | `storeOrder` recomputes via the same functions `verify` used |
| Coupon eligibility/consumption | `CouponRepository` (row lock + UNIQUE ledger) |
| Stock | atomic decrement (policy-gated blocking) — client stock values never trusted |
| Payment status | server-side gateway verification + signed webhooks only |
| Vendor authorization | RBAC middleware + per-shop scoping |
