# PlantAtHome — Security Findings (canonical register)

**This file + the admin Security Audit page dataset (`admin/rest/src/data/security-audit.ts`) are
the canonical record.** The dataset is the operator-facing view; this file is the engineering
register. `api/SECURITY_AUDIT_2026-08.md` (2026-08-07) is the frozen original pentest report —
historical, superseded where they disagree.

## Lineage

1. **2026-08-07 full-stack pentest** (`api/SECURITY_AUDIT_2026-08.md`): headline = unauthenticated
   `GET /api/vendors` + `/api/shops` leaking supplier bank/IFSC/PAN/GST/UPI on prod — closed same
   day (`c6edf67`). OTP throttle fixed (phone-keyed; the IP key never fired behind Cloudflare's
   XFF normalization). IDOR sweep, mass-assignment, guest-order token model.
2. **2026-08-09 hardening** (R1–R3 on the admin page): concurrency-safe usage-limited coupons
   (UNIQUE ledger + row lock, 100-attempt test), layered `OtpAbuseGuard`
   (cooldown/day-cap/verify-lockout, fail-open over the throttle floor, live-verified 429),
   readiness/liveness split, coupon `target` guard at order creation. 401-not-500 fix.
   `SANCTUM_EXPIRATION_MINUTES=43200`.
3. **2026-08-10 enterprise pass (this register's additions, R4–R9):**

| ID | Sev | Finding | Fix | Test |
|---|---|---|---|---|
| R4 | HIGH | `POST /orders`: no idempotency, no throttle → duplicate orders / double charge exposure, PSP-intent spam | Idempotency-Key + unique column + throttle:20,1 | `DuplicateCheckoutTest` |
| R5 | HIGH | 11 unauthenticated, unthrottled gateway webhook endpoints — aliases into the live active-gateway handler; 9 gateways unused | `WEBHOOK_GATEWAYS` allowlist (404 default-closed for unused; razorpay fails closed w/o secret) + `throttle:120,1` ×12; `exit()`→`abort(400)` | `RazorpayWebhookTest` |
| R6 | MED | Financial-state leaks on abandonment: stock deducted + coupon consumed forever when payment never completes (webhook `failed` is deliberately retry-friendly) | `orders:cancel-stale-unpaid` sweep through the idempotent cancel machinery | `StaleUnpaidSweepTest` |
| R7 | MED | Oversell: order creation never refused for stock (stubbed check) — inventory manipulation by racing checkout | Atomic decrement is the gate; `block` policy = 422+rollback | `InventoryConcurrencyTest` (qty=1 ×100 ⇒ 1) |
| R8 | LOW | Enumeration/abuse surfaces unthrottled: `coupons/verify`, token-URL downloads, `license-key/verify`, `logout`, `shop-maintenance-event`, v1 search | Per-route throttles | route review |
| R9 | LOW | `routes/api.php /user` on the `api` guard (500s on bad tokens vs 401) | → `auth:sanctum` | — |

## Verified-in-place controls (attack-surface map)

- **AuthN**: Sanctum bearer (30d expiry, revoke-all-devices); OTP layered guard (send cooldown 45s,
  8/24h/phone, 5-fail→15min verify lockout; Redis-backed, fail-open ABOVE `throttle:otp` floor —
  a cache blip can't lock everyone out, the throttle still binds).
- **AuthZ**: 96-permission RBAC + designations; vendor shop-scoping (RbacTest 403s);
  super-admin groups; module tier uniformly `v1.auth`/`v1.role:`.
- **Payments**: Razorpay webhook = raw-body signature verification (fail-closed 400), captured-amount
  vs intent recheck, state-based replay idempotency (verified no-refire in tests), allowlist + throttle.
  Server-side payment status only; frontend never trusted.
- **Money invariants**: server-authoritative discount/fee/tax recompute at order creation (tampered
  client figures corrected); coupon target/window/exhaustion enforced at creation; wallet row-locked.
- **Injection/XSS**: sanitize-html 2.17.5 (pinned; 2.17.6 needs Node 22); Eloquent bindings;
  remaining Prettus request-criteria injection review is on the roadmap register (pre-existing note).
- **Webhooks (non-payment)**: sendgrid throttled; shipping callback token-verified + idempotent +
  never-5xx.
- **Secrets**: none in code (gateway keys via env; integration creds AES-GCM in `integration_providers`).

## Open items (operator)

| ID | Item |
|---|---|
| H7 | Deployed plant-doctor ≠ source — reconcile + redeploy from repo (recipe in production-readiness.md) |
| H8 | Rotate the secret once committed in `chatbot-service/DEPLOY_ASK_AI.md` |
| A3 | Restrict EC2 origin firewall to Cloudflare ranges (makes trusted-proxy airtight) |
| — | Razorpay LIVE key (test key = no real payment has ever traversed the money path) |

## Logging red-lines (enforced by review)

Never log: passwords, OTP values, payment secrets, API keys, bearer tokens. `request_logs` stores
metadata + enriched IP only; OTP guard logs counters, never codes.
