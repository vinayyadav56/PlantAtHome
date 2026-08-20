# Shipping providers — architecture, status sync, troubleshooting

_Last verified live: 2026-08-11 (staging). Owner docs — no secrets here; env var NAMES only._

## Architecture

```
Shop checkout ──► Laravel API (orders, shipments: ONE per vendor per order)
                        │  ShippingServiceClient (X-Api-Key)
                        ▼
              Go shipping-service (App Runner, Postgres)
                        │  partner.Adapter interface:
                        │  Quote / Book / Cancel / Track / NormalizeWebhook
                        │  (+ optional: AssignAWB, RegisterPickup, RefreshToken, Label, Pickup)
        ┌───────────────┼──────────────────┬──────────────────────┐
        ▼               ▼                  ▼                      ▼
     Porter           Borzo          Shiprocket            Shiprocket Quick
  (instant/       (instant/         (courier —            (instant/same_city —
   same_city)      same_city)        intercity AWB)        hyperlocal riders)
```

- **Routing**: `svc.Book` → `Registry.CandidatesForMode(mode, cod)` → cheapest quote wins
  (mode_priority can prefer a partner). Partners are declared by `Descriptor` (code, priority,
  required credentials, capabilities) in `internal/partner/descriptor.go`; NO if/else partner
  chains outside the adapters.
- **One shipment per vendor**: `OrderItemService` groups order items per `(shop, fulfillment_mode)`.
  A LIVE-BOOKED shipment is SEALED — re-plans never delete it, its items refuse reassignment
  ("cancel it first"). Rebook after cancel reopens the service ref with `book_attempt` folded
  into every partner idempotency key (Porter request_id, Borzo ware_code, Shiprocket order_id).
- **Booking payload** carries ALL the vendor's line items (`items[]`, weight); dims currently
  default 20×15×15 cm (product dims columns exist but are unpopulated — data task).

## Validation is PER LANE (`internal/partner/validate.go`)

| Lane | Requires | Why |
|---|---|---|
| hyperlocal (instant/same_city) | coords + phones at BOTH ends | riders navigate by pin-drop; 0,0 books the Gulf of Guinea |
| courier | pickup+drop PINCODE + drop phone | Shiprocket is pincode-keyed; pickup side comes from the REGISTERED pickup location, not the wire |

## Shiprocket (standard, `shiprocket`)

- Auth: `POST /v1/external/auth/login` (email/password → ~9-day bearer, cached; or permanent
  api_token). Credentials come from the admin **Integration Management** sync (DB, AES-GCM) with
  env (`SHIPROCKET_EMAIL/PASSWORD/API_TOKEN`) as fallback. Verify with admin → token refresh.
- **Pickup location MUST be registered** before the first booking of a shop: nickname `shop-{id}`
  via admin "Register pickup" → API `shops/{id}/sync-pickup` → service
  `POST /v1/partners/shiprocket/register-pickup` → Shiprocket `settings/company/addpickup`
  (WITH lat/long — the Quick prerequisite). Idempotent. Shiprocket demands a house/flat/road
  number in address line 1.
- Book = create adhoc order → AssignAWB. **Order-created-but-AWB-pending is a SUCCESS** (prevents
  double-booking); the state is recorded (`last_status='awb_pending'` + timeline event + admin
  pill "Booked · AWB pending") and the 10-min reconcile completes the AWB. AWB stuck long-term
  usually = Shiprocket wallet balance / no courier assignable — check the Shiprocket dashboard.

## Shiprocket Quick (`shiprocket_quick`) — hyperlocal lane

**Not a separate API.** Same account, same login/token, same apiv2 endpoints, field-level
differences (verified against the official Postman collection + live API):

| Op | Endpoint | Quick specifics |
|---|---|---|
| Serviceability | `GET /v1/external/courier/serviceability/` | `is_new_hyperlocal=1&only_local=1` + `lat_from/long_from/lat_to/long_to` (all required) |
| Book | `POST /v1/external/orders/create/adhoc` | `shipping_method:"HL"` + drop `latitude`/`longitude`; then the same `assign/awb` |
| Track | `GET /v1/external/courier/track/awb/{awb}` | shared; adds rider statuses |
| Cancel | `POST /v1/external/orders/cancel` | no Quick-specific endpoint |

- Shares Shiprocket's credential row (`Descriptor.CredsFrom = "shiprocket"`) — one credential
  serves both partners.
- **Ships DISABLED** (`DefaultDisabled`): with no operator config row it is excluded from
  routing. Enable via admin Settings → Courier → Shiprocket Quick AFTER serviceability probes
  succeed for your cities. COD supported (collectible = sub_total; no separate cod_amount field).
- Status mapping (shared map, `mapShiprocketStatus`): SEARCHING_FOR_RIDER→pending,
  RIDER ASSIGNED→assigned, RIDER UNASSIGNED→no-op, PICKED UP→shipped,
  RIDER REACHED AT DROP / OUT FOR DELIVERY→out_for_delivery, DELIVERED→delivered,
  RTO*→rto (checked first), CANCEL*→cancelled.
- **Account status (2026-08-11)**: live probe (Bengaluru intra-city) answered
  `No Courier Serviceable` — Quick isn't enabled/serviceable on this account/route yet.
  Ask Shiprocket to enable hyperlocal, then re-probe via admin → Shipping Integrations →
  Shiprocket Quick → Quote. Docs don't publish coverage; only probes answer it.
- Known unknowns (account-only): rich vs degenerate serviceability response (both parsed),
  Quick courier ids, cancellation window once a rider is assigned, rate card.

## Porter / Borzo status sync (unchanged architecture — verified healthy)

Backend OWNS status sync; the frontend never polls providers:
1. **Webhooks** → `POST /webhooks/{partner}` (token-gated; every callback logged with
   `signature_valid`; always acks 200).
2. **Reconcile** — 10-min force-track poll of all non-terminal bookings (survives the
   track cost-switch; now also skips `rto`).
3. **Outbox → monolith** — every real transition → `outbox_events` → relay (2s drain,
   backoff ≤1h, retries forever) → Laravel `/api/shipping/callback` (`hash_equals` on the
   callback key) → `applyNormalizedStatus` (monotonic, terminal-sticky) → order advance.

Normalized lifecycle: `pending → assigned → packed → shipped → out_for_delivery → delivered`,
interrupts: `cancelled`, `rto` (both terminal; rto deliberately does NOT advance the order).

**Config failure modes that freeze status on OUR side (check these first):**
| Symptom | Cause | Check |
|---|---|---|
| Service DB advances, monolith frozen | callback key drift (relay 401s, retries forever) | Go `MONOLITH_CALLBACK_KEY` == Laravel `SHIPPING_SERVICE_CALLBACK_KEY` (✅ verified equal on staging 2026-08-11) |
| Events acked but discarded | courier master switch OFF window (Laravel replies 200 "note") | settings.options.courier.enabled |
| No events at all | `MONOLITH_CALLBACK_URL` unset (relay never starts) | service env |
| Webhooks all rejected | partner webhook token unset | `PORTER_WEBHOOK_TOKEN` / `SHIPROCKET_WEBHOOK_TOKEN` (✅ set on staging) |

## Error string → root cause (admin sees these verbatim)

| Error | Meaning / fix |
|---|---|
| `invalid shipment request: …pincode…/…phone…` | courier-lane data missing on the order/vendor |
| `invalid shipment request: …no coordinates…` | hyperlocal lane; vendor/customer has no geocode |
| `no partner serves this mode` | no credentialed+enabled partner for the lane — check partner cards |
| `partner not available: X` | explicit partner excluded (creds/toggle/mode) — quote panel's "Not asked" says why |
| `shiprocket: order not created (422) …` | Shiprocket's own reason (pickup nickname unknown, address line needs house no., empty items) |
| `shiprocket: auth failed (401)` | bad credentials — re-sync via Integrations, then token refresh |
| `shiprocket: order created, AWB pending: …` | order EXISTS; AWB completes via reconcile; long-stuck = wallet/courier at Shiprocket |
| `This vendor shipment is already booked…` | duplicate-booking guard — cancel first to rebook |
| `shipment is already being booked or is cancelled` | concurrent claim / stale state — retry after a minute |
| `Courier is off or the shipping service is not configured.` | monolith master gate |

## Env var matrix (names only)

Service (App Runner): `SERVICE_API_KEY`, `MONOLITH_CALLBACK_KEY`, `MONOLITH_CALLBACK_URL`,
`CREDENTIALS_KEY`, `INTEGRATION_SYNC_KEY`, `DATABASE_URL`, `PORTER_API_KEY`, `PORTER_BASE_URL`,
`PORTER_WEBHOOK_TOKEN`, `BORZO_*`, `SHIPROCKET_BASE_URL`, `SHIPROCKET_WEBHOOK_TOKEN`
(+ optional `SHIPROCKET_EMAIL/PASSWORD/API_TOKEN` env fallback — staging runs on DB-synced creds).
Laravel: `SHIPPING_SERVICE_URL`, `SHIPPING_SERVICE_API_KEY`, `SHIPPING_SERVICE_CALLBACK_KEY`.

## Testing each provider (no mocks)

Admin → Settings → Shipping Integrations → pick partner → Quote / Track / Book / Cancel — the
console shows the RAW partner exchange (credentials masked) and runs from the whitelisted egress
IP. Book/Cancel create/cancel REAL orders (typed confirmation on production). Quick works here
while its routing toggle is off. Full booking regression 2026-08-11: token refresh ✅, pickup
registration ✅ (+ idempotent re-run), real standard order created `1509272853` ✅ (AWB pending —
account-side), cancelled ✅.
