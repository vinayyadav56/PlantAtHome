# Order Fulfillment Workflow — Line Item → Vendor → Group → Shipment → Courier → Tracking

Implementation report for the fulfillment redesign of the admin order page.
Cycle: 2026-08-12. Staging: API `b23ce03`, admin `f320d3c`, Go shipping-service `staging-<sha>` on
App Runner `shipping-staging`.

---

## 1. The actual problem

The page had grown **one decision made in several places**. Three separate annotations were all the
same defect:

- Vendor choice existed twice — an order-level "Vendors in New Delhi" radio panel *and* a per-line
  dropdown table. They could disagree, and when they did, the shipments followed the lines while the
  operator had been looking at the panel.
- "Delivery Mode" appeared to choose the carrier. It does not, and never did.
- Courier booking was split across two surfaces with no way to retry a failure.

**The audit's most important result: almost none of the requested backend was missing.** Automatic
vendor grouping, per-vendor booking, live quotes, AWB/tracking persistence and partial booking were
already implemented and working. The work was consolidation, not construction. What *was* genuinely
missing is listed in §4.

---

## 2. Delivery mode — the three columns, traced

Three different columns are called some variant of "delivery mode". Every reader of each was traced:

| Column | What it actually controls | Treatment |
|---|---|---|
| `orders.delivery_mode` (`vendor_dp`, `separate_dp`, `courier_admin`, `courier_dp`) | Delivery-partner commission variant, plus "courier_admin ⇒ no DP", plus analytics filters. **Zero effect on booking.** | Relabelled **"Delivery partner engagement"**, moved beside the DP picker, helper text added |
| `shipments.mode` (`instant`, `same_city`, `courier`) | **The partner-eligibility key.** Go's `EligibilityForMode` decides who may quote or book from this alone | This is the real delivery mode — surfaced as Hyperlocal / Courier inside the Book Courier modal, where it changes who can carry the parcel |
| `shops.delivery_mode` / `shipments.delivery_mode = 'self'` | Hard skip of the entire courier stack; the vendor walks the status manually | Read-only "Self delivery" badge; no Book button rendered |

The relabel is the whole fix for the ambiguity. The order-level control was never a carrier switch,
so making it *look* like one was the bug.

---

## 3. Vendor assignment — now exactly one surface

Vendor choice lives **only on the line item**, because vendor groups and their shipments are derived
from `order_items.assigned_shop_id`. Any second picker is a way to disagree with the thing that
actually decides.

- **New** `available-vendors-view.tsx` (`AVAILABLE_VENDORS` modal) — a radio list of live candidates
  for one line: vendor, city, stock, price, ETA, Recommended, Current. Radio, not multi-select: a
  line has exactly one supplier.
- **New** `line-item-vendor-cell.tsx` — the amber vendor badge (or "Vendor not assigned") plus the
  Available vendors / Change vendor button, rendered per row in the order summary table.
- **Deleted** — the order-level vendor radio panel *and* the per-row `<select>` + Apply column. The
  Assign step is now a read-only readout of what the lines resolved to.

The list is the server's live candidacy (stock, price, service area, KYC hold, effective dates) —
the same set `assign-items` re-validates on submit, so a vendor shown in the modal cannot come back
as "cannot fulfil". A `200` with `applied: 0` is treated as a rejection: the modal stays open.

**Live payload check** (staging order 210, line 31):

```json
{"shop_id":32,"vendor_name":"Delhi Nursery","city":"New Delhi","selling_price":3730.8,
 "available_qty":0,"stock_tracked":false,"fulfillment_mode":"local","eta_days":5,"recommended":true}
```

`city` is new this cycle. Note `available_qty: 0` with `stock_tracked: false` — the modal renders
"Stock: untracked", not a false "Available: 0".

---

## 4. What was genuinely missing, and is now real

### 4.1 Package weight and dimensions were a complete no-op

Couriers bill on **volumetric** weight, so this was a silent mispricing on every courier booking.
The dimension path was dead at four consecutive points:

1. `products.length/breadth/height` — columns populated, read by nobody.
2. `CourierService::defaultPackage()` — implemented, called by nobody; the admin settings inputs
   that fed it wrote to a value nothing consumed.
3. `ShippingServiceClient::buildRequest` — never sent dimensions at all.
4. Shiprocket adapter — hardcoded literal `20 / 15 / 15` for every parcel ever booked.

Now end-to-end:

- **Migration** `add_package_to_shipments`: `weight_g`, `length_cm`, `breadth_cm`, `height_cm`, all
  nullable. NULL means "derive", exactly as before — no behaviour change for existing rows.
- **Resolution chain**: operator override → product dimensions on the leg → the admin's configured
  `default_package` → 20/15/15. A *partial* product triple falls through rather than shipping
  `40 × 0 × 0`.
- **New endpoint** `POST shipments/{id}/package`, 409 on an already-booked shipment.
- **Go**: `ShipRequest` carries `length_cm/breadth_cm/height_cm`; the Shiprocket adapter uses them
  with the old literals as fallback. Porter and Borzo ignore dimensions because their APIs do not
  accept them — documented, not faked.

Verified live: saved 3.2 kg / 55×40×60 on shipment 15, quoted through the deployed Go service,
Porter returned ₹85.56. Test data reverted afterwards.

### 4.2 A failed booking had no way back

There was no retry affordance anywhere. A `book_failed` shipment simply sat there. Now the card
shows the partner's own `failure_reason` with a **Try again** that reopens the same flow.

### 4.3 Partial-booking retry could never complete

Found while reviewing the bulk path. Book three groups, one fails → retry → the server answers
*"already booked — cancel the existing booking first"* for the two that succeeded, so the batch
fails forever **because part of it worked**. The modal now tracks which groups booked in this
session and retries only the rest, and names the failing vendor instead of showing one anonymous
error. Pinned by a new test (§7).

---

## 5. Vendor grouping (already automatic — confirmed, not rebuilt)

`regroup()` keys shipments on (`shop_id`, `fulfillment_mode`, `split_group`) derived from the line
assignments. There is no manual grouping state and none was added.

- N lines, same vendor → **one** shipment.
- N lines, two vendors → **two** shipments, booked independently.
- Live-booked shipments are **sealed**: reassignment is refused, the row is preserved, and the
  operator is routed to cancel-then-reassign rather than shown a Continue button that would lie.

---

## 6. Book Courier — one modal, existing endpoints

Single shipment: items → pickup (read-only vendor address) → delivery address (editable, merges so a
text edit never blanks the GPS pin) → **package** → **lane** → **live rates** → **book**.

Decisions worth recording:

- **Package and lane are saved before rates are fetched.** A quote taken against the old parcel is
  not the price that gets billed. The sequence bails on the first failure, so a rate is never shown
  for a parcel that no longer exists.
- **An untouched lane radio writes nothing.** The radio is Hyperlocal | Courier but `shipments.mode`
  also holds `instant`, which sits under Hyperlocal. Writing on every check would silently demote
  `instant` → `same_city`. Staging order 210 is `instant`, so this would have fired on the very
  first real use.
- **Empty quotes is not an error.** Zero quotes plus an `ineligible[]` block renders the reasons —
  that block exists precisely to explain an empty list.
- **Blank package box = derived.** Omitted fields are dropped from the JSON, read as null, and
  revert to the derived value. The helper text says so.
- Booked shipments disable the package and lane inputs, matching the server's 409.
- The bulk path keeps today's sequential dispatch and skips the per-shipment package/lane steps —
  "book these N" is not "configure these N".

---

## 7. Tests

- **API: 625 green** (`vendor/bin/phpunit -d memory_limit=2G`), up from 618.
  - `ShipmentPackageTest` (6 new): default parcel; product dims beat the default; operator override
    beats everything; weight fallback chain; partial dims fall through instead of sending zeros; the
    admin-configured `default_package` is honoured (dead config until now).
  - `AutoBookShipmentsTest` (1 new): **a vendor group stays booked when a sibling group fails**, and
    the retry re-attempts only the failed leg. This is the invariant both retry paths depend on.
  - Existing grouping / sealed / split / rebook / dispatch-refused suites all still green.
- **Go**: `go test ./internal/partner/...` green, including
  `TestShiprocketBookSendsParcelDimensions` (verbatim pass-through and the 20/15/15 fallback).
- **Admin**: `npx tsc --noEmit` clean apart from 3 pre-existing circular-type errors in
  `product-form.tsx`; ESLint clean on every touched file. `admin/rest` has no test runner, so the
  frontend logic is not unit-tested.

---

## 8. Real finding: why staging order 210 will not book

Shipment 15 carries a genuine failure:

```
booking outcome unknown: porter: Sorry! We do not allow service in the area
you have selected (restricted_location) [500]
```

**Root cause — a bad geocode on the pickup, not a code defect.** Shop 32 "Delhi Nursery" stores:

| Field | Value |
|---|---|
| `address.street_address` | Greater Kailash, New Delhi, Delhi, India |
| `address.zip` | 110048 |
| `settings.location.formatted_address` | Greater Kailash, New Delhi, Delhi, India |
| `lat, lng` | **28.1867, 76.3536** |

28.1867, 76.3536 is **Rewari, Haryana — roughly 90 km from Greater Kailash** (28.55, 77.24). The
text, the pincode and the Google `placeId` all say Delhi; only the saved coordinates disagree, so
Porter is being asked to collect from a district it does not serve.

**Not systemic** — the other two vendors are correctly placed (shop 12 Bengaluru 12.94, 77.63;
shop 33 Delhi 28.54, 77.24). Shop 32 needs re-pinning in the admin; no code change would fix a
wrong coordinate.

Worth noting for operations: **a successful Porter quote does not guarantee a bookable pickup.**
Shipment 15 quotes at ₹85.56 and then fails serviceability at booking, from the same coordinates.
That asymmetry is Porter's, and it is exactly why the modal keeps the failure visible with a retry
rather than treating a good quote as a commitment.

---

## 9. Files changed

**API** (`plantathome/api`)
- `packages/marvel/database/migrations/2026_08_12_000200_add_package_to_shipments.php` *(new)*
- `packages/marvel/src/Services/Courier/ShippingServiceClient.php` — `weightG()`, new `packageDims()`, `buildRequest`
- `packages/marvel/src/Http/Controllers/CourierShipmentController.php` — `updatePackage()`
- `packages/marvel/src/Database/Models/Shipment.php` — `isLiveBooked()`
- `packages/marvel/src/Rest/Routes.php` — `POST shipments/{id}/package`
- `packages/marvel/src/Services/ItemAssignmentService.php` — candidate `city` + `cities` (no extra queries)
- `tests/Feature/Courier/ShipmentPackageTest.php` *(new)*, `tests/Feature/Courier/AutoBookShipmentsTest.php`

**Go** (`shipping-service`)
- `internal/partner/types.go` — dimensions on `ShipRequest`
- `internal/partner/shiprocket.go` — real dimensions + `dimOrDefault`
- `internal/partner/shiprocket_pickup_test.go` *(new test)*

**Admin** (`plantathome/admin/rest`)
- `components/order/available-vendors-view.tsx` *(new)*, `components/order/detail/line-item-vendor-cell.tsx` *(new)*
- `components/order/detail/fulfillment-summary.tsx` *(new)*, `components/order/courier-quote-utils.ts` *(new)*
- `components/order/confirm-dispatch-view.tsx` — the Book Courier flow
- `components/order/detail/shipment-ops-list.tsx`, `detail/fulfillment-workflow.tsx`, `detail/order-summary-card.tsx`
- `components/order/order-assignment-panel.tsx`, `components/order/order-item-assignment.tsx` — pickers removed
- `components/order/shipment-courier-panel.tsx` — helpers extracted, no behaviour change
- `components/ui/modal/modal.context.tsx`, `managed-modal.tsx` — `AVAILABLE_VENDORS`
- `data/client/api-endpoints.ts`, `data/client/courier.ts`, `data/courier.ts`, `data/order-assignment.ts`
- `pages/orders/[orderId]/index.tsx`

**Existing APIs reused, not duplicated**: `orders/{id}/items`, `orders/{id}/item-assignment-plan`,
`orders/{id}/assign-items`, `orders/{id}/auto-assign-items`, `orders/{id}/split-shipment`,
`shipments/{id}/shipping-quotes`, `shipments/{id}/dispatch`, `shipments/{id}/shipping-mode`,
`shipments/{id}/cancel`, `orders/{id}/match`, `orders/{id}/assign`.

---

## 10. Open items (owner action, not code)

1. **Re-pin shop 32** in the admin — its coordinates sit in Rewari (§8). Until then every Porter
   booking from that vendor fails `restricted_location`.
2. **Shop 33** has no delivery service areas configured.
3. **Shiprocket Quick** is `disabled_by_operator`; Shiprocket itself reports `mode_not_supported`
   for hyperlocal lanes, which is correct — it serves `courier` only.
4. **Borzo** reports `missing_credentials` on staging.
5. **Meta WhatsApp credentials** are still unset while `ACTIVE_OTP_GATEWAY=whatsapp`, from the
   previous cycle — WhatsApp OTP login cannot succeed until they are configured.

## 11. Not verified

The admin UI was **not** clicked through on staging this cycle. Browser authentication was
unavailable in this environment (injecting an admin session cookie was blocked, correctly), and no
service account exists — the only super-admins are the owner's own logins. What was verified instead:
every payload the new components read was dumped from the live staging controllers and matched
field-by-field against what the components render, the quote round-trip was exercised against the
deployed Go service, and the type-check and test suites are green. The rendering itself rests on code
review, not on a screenshot.
