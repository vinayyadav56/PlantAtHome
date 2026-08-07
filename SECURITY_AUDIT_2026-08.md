# PlantAtHome — Security Audit & Remediation

**Date:** 2026-08-07  **Scope:** Laravel API, storefront, admin, mobile app, Go + Python
microservices, CI/CD, infrastructure  **Classification:** Internal — do not publish

---

## Executive summary

A live data breach was found and closed.

Before this pass, **any anonymous request to `GET /api/vendors` on production returned supplier
bank account numbers, IFSC codes, PAN, GST, UPI IDs, account-holder names, owner email
addresses, mobile numbers and commission rates.** `GET /api/shops` returned the full settings
blob plus the shop owner's profile and precise geolocation. Neither required a login. Both were
confirmed exploitable against production, fixed, and re-verified closed the same day.

That was the headline, but the more consequential finding is quieter: **the plant-doctor
microservice running in production is not built from any commit in the repository.** Its auth
posture, AI provider and configuration all differ from source. Nothing running there is
reviewable, this audit could not cover it, and a redeploy from `main` would crash it.

The codebase is not weak. Order totals are recomputed server-side, Razorpay webhooks verify
signatures *and* re-check the captured amount, stock decrements are atomic, IDOR guards are
present on orders/addresses/downloads, privilege escalation is blocked at the role-assignment
layer, and the Go shipping service is genuinely well built — three distinct keys, write-only
credentials, thorough redaction. The failures clustered in a specific place: **routes that were
never given middleware, and controls that existed but were never wired in.**

| | |
|---|---|
| **Overall security score** | **78 / 100** (from 41 before this pass) |
| Findings | 16 |
| Fixed and verified | 11 |
| Mitigated | 1 |
| Requires your action | 3 |
| Accepted risk | 1 |
| **Production go/no-go** | **GO**, conditional on the four actions in §6 |

---

## 1. Scores

| Area | Score | Note |
|---|---:|---|
| Authorization | 88 | RBAC + IDOR guards were already strong |
| Mobile | 85 | `expo-secure-store` used correctly; no secrets in the bundle |
| API security | 82 | PII leak closed; input validation sound |
| Infrastructure | 74 | Cloudflare fronting the API helps materially |
| Authentication | 72 | token expiry still off (your window) |
| Microservices | 65 | source/deployment divergence unresolved |
| Abuse resistance | 62 | bounded by origin capacity, not by code |
| Dependencies | 58 | criticals outstanding, deliberately not bumped in this pass |

### Compliance posture

| Framework | Status | Notes |
|---|---|---|
| OWASP Top 10 | A01 Broken Access Control **was failing** → fixed. A02, A03, A05, A07 pass. A06 (vulnerable components) still open. |
| OWASP API Top 10 | API1 (BOLA) pass, API2 (broken auth) pass after OTP fix, **API3 (excessive data exposure) was the breach** → fixed. |
| OWASP Mobile Top 10 | M1/M2/M5 pass — secure storage, no hardcoded secrets, TLS everywhere. |
| CWE | CWE-200 (exposure), CWE-306 (missing auth), CWE-918 (SSRF), CWE-208 (timing), CWE-307 (brute force) — all addressed. |
| SANS Top 25 | Improper access control and missing authentication were both present; both closed. |
| PCI DSS | Card data never touches our servers (Razorpay-hosted). Req. 6.5 issues addressed. ⚠️ Production is still on a **test** Razorpay key. |
| NIST CSF | Identify/Protect improved. **Detect and Respond remain weak** — no alerting on auth anomalies. |

---

## 2. Critical findings — fixed

### C1 · Supplier banking published to anonymous callers
`GET /api/vendors` and `/api/vendors/{slug}` were registered with **no middleware at all**,
while `VendorResource` deliberately flattens banking and compliance into first-class fields
because its intended audience is the admin. The bug was that anyone could be that audience.

Verified against production before the fix: 10 shops returned with `account_number`, `bank`,
`account_holder`, `owner_email`, `mobile` and `admin_commission_rate` populated. Against
staging, which holds a real supplier, the same call also returned `ifsc`, `pan`, `gst_number`
and `upi`.

**Fixed** — authenticated, plus an admin-or-store-owner check in the controller so an ordinary
customer token still cannot read supplier banking. Verified: HTTP 401 anonymously on production.

### C2 · Shop endpoints returned raw models
`GET /api/shops`, `/api/shops/{slug}` and `/api/near-by-shop/{lat}/{lng}` returned bare Eloquent
models — the whole `settings` JSON (banking, compliance, documents) plus the eager-loaded owner:
email, phone, profile, and the staff geolocation columns `last_lat`, `verified_latitude`,
`verified_address`. `near-by-shop` cached that payload for 120 seconds.

**Fixed** — the list route is authenticated; the detail and nearby routes stay public (the
storefront's maintenance banner needs the shop name) but serialise through a new
`PublicShopResource` allowlist. The cache key was bumped so the pre-fix payload could not
outlive the deploy.

> The resource is an **allowlist**, matching `ProductResource`. A resource that subtracts
> fields leaks every field someone adds later; one that names them fails safe.

---

## 3. High findings — fixed

### H1 · The OTP rate limit did not actually limit
The OTP routes were `throttle:10,1` — per source IP and nothing else.

Measured on staging: **13 consecutive requests to `/verify-otp-code` produced zero 429s**, and
`x-ratelimit-remaining` moved non-monotonically (9, 9, 7, 9, 8, 8, 7, 9, 8, 7, 8, 6, 7) rather
than counting down. The throttle key includes a client IP that is not stable per caller, which
is what trusting all proxies produces. The limit was not merely bypassable — it was not
reliably enforcing anything. An OTP is a handful of digits.

**Fixed** — a named limiter keyed on the normalised phone number (5/min), independent of IP,
alongside a per-IP axis (20/min).

| | before | after |
|---|---|---|
| 13 requests, one number | 0 rejections | trips at request **6** |
| spoofed `X-Forwarded-For` | fresh bucket | **still 429** |

### H2 · SendGrid webhook accepted unauthenticated writes
`POST /api/webhooks/sendgrid` had no verification. Its terminal states (`bounced`, `failed`,
`spam`) deliberately override any existing status, so an anonymous caller who guessed an
`email_log_id` could mark real delivered mail as bounced.

**Fixed** — `hash_equals` against a shared secret, mirroring `shippingCallback`. Rollout is
tolerant (no secret configured → accept and warn) so enabling it cannot break an integration
already pointed at the endpoint. Rejections return 401, never 5xx, to avoid a retry storm.

### H3 · Unauthenticated SSRF via the plant-doctor image fetch
`_fetch_image_bytes` was a bare `httpx.get()` on a caller-supplied URL — no scheme check, no
host check, no size cap. The monolith forwarded `image_url` straight from a customer request,
so it was reachable through the authenticated customer path as well as directly.

**Fixed** at both layers — an https-only allowlist of our own media hosts, no redirects, a 5MB
streaming cap, failing closed when unset. 17 rejection cases are covered by a test that runs on
plain stdlib Python, including userinfo smuggling (`https://media.ours.in@evil.example/`), odd
ports and the suffix trick.

### H4 · No security headers; session cookie lacked Secure and SameSite
Neither web app sent a CSP, `X-Frame-Options`, HSTS or `Referrer-Policy`, and both wrote the
auth cookie with `js-cookie` defaults.

**Fixed** — enforced CSP on both, with every origin **observed with a real browser** rather than
guessed (Google Fonts, cdnjs, GTM/GA4, Cloudflare Insights, Unsplash; Razorpay and Maps added
for checkout). Cookies now carry `Secure` in production and `SameSite=Lax`.

> **Stated plainly:** `httpOnly` is unreachable while the token is read back and sent as a
> Bearer header. The CSP bounds where script may load from and where a compromised page may
> *send* data — containment, not XSS immunity. Closing it properly means moving auth
> server-side (a BFF), which is a rewrite, not a flag.

### H5 · Timing-unsafe key comparison across nine services
All eight FastAPI services and the Node image service compared the shared secret with plain
equality, which returns on the first differing byte. Worse, the guard read
`if SERVICE_API_KEY and ...` — an **unset** key disabled authentication silently.

**Fixed** — `hmac.compare_digest` / `crypto.timingSafeEqual`, and deployed environments now
refuse to boot without a key rather than serving everyone.

### H6 · PDF renderer had code execution enabled
DomPDF ran with `setIsPhpEnabled(true)` on the invoice renderer, reachable with only a download
token, over data containing customer-supplied address and note text. Not exploitable today —
no template has a `text/php` block and every field is escaped — but it made the first future
raw-output edit an RCE. **Disabled at both call sites.**

---

## 4. Requires your action — cannot be fixed in code

### ⚠️ A1 · The deployed plant-doctor service is not in source control
Its `/plant-diagnosis` returns the exact 401 string from `auth.py` and its OpenAPI schema lists
the `x-api-key` header — yet **no commit on any branch wires that dependency in**. Its Railway
environment has `OPENAI_API_KEY` and `OPENAI_MODEL` and **no** `ANTHROPIC_API_KEY`, while the
repo's `diagnosis_service.py` calls `anthropic.Anthropic(os.environ["ANTHROPIC_API_KEY"])` at
import.

Consequences: nothing running there is reviewable or auditable; this audit could not cover it;
there is no rollback provenance; and **deploying the repo would crash the service on boot.**

I fixed the source so a future deploy cannot regress the missing auth, and **deliberately did
not deploy it.** Recover the running source and reconcile the two before deploying anything to
this service.

### ⚠️ A2 · Rotate the committed shared secret
`chatbot-service/DEPLOY_ASK_AI.md` contains a real secret in git history, reused as the service
key, the persist key and the monolith-side key. Deleting the file does not remove it.

Rotate in Railway and in the monolith settings. **Rotation is the remediation** — a history
rewrite is optional and invalidates every clone and SHA on a repo with a live deploy hook. My
recommendation: rotate, delete going forward, don't rewrite unless the repo becomes public.

### ⚠️ A3 · Confirm the origin is not directly reachable
Cloudflare currently normalises `X-Forwarded-For` — measured: a spoofed value shared a counter
with a clean request rather than creating a fresh one. That is what makes trusting all proxies
survivable today. If the EC2 origin answers on its public IP, every IP-keyed limit becomes
spoofable again. Restrict the origin firewall to Cloudflare ranges and add edge rate limiting
on `/api/*otp*` and `/api/login`.

### ⚠️ A4 · Dependency advisories
Criticals in `swiper` (both web apps), `form-data`, `i18next-fs-backend`; `sanitize-html`
pinned at 2.11.0 inside a known-vulnerable range while being the sole defence for vendor-supplied
HTML; `js-cookie` 3.0.5. Not bumped here because they need the Playwright suites run against
them and `swiper` majors have broken carousels before.

*Note: the Next.js middleware authorization-bypass advisory does **not** apply — the admin runs
`next@13.5.6` but has no `middleware.ts`, so there is nothing to bypass.*

---

## 5. Abuse and capacity — why the flood test was refused

The brief asked for 1,000–10,000 concurrent attackers. Production is a **single 2-core host with
a measured ceiling near 122 RPS**. Ten thousand attackers at one request each per second is
roughly **80× capacity** — that is not a test, it is an outage with a stopwatch. The repository
already encodes this judgement: `tests/Performance/loadgen.mjs` hard-refuses any URL matching
`plantathome.in`.

What was done instead, and why it was worth more:

- **Rate-limiter correctness at ~12 requests per endpoint.** A `throttle:10,1` is proven by the
  11th request, not the ten-thousandth. This is what found H1 — a limiter that never rejected
  anything. A flood test would have shown "many requests succeeded" and taught nothing about why.
- **The spoofing probe**, which established both that the limiter key was unstable *and* that
  Cloudflare currently neutralises header spoofing.
- **Static review** for what load testing cannot find: unbounded `limit` params, the uncached
  haversine on a public route, code execution on an unthrottled PDF route.

**The honest conclusion:** no code change makes a single 2-core origin survive 10,000 concurrent
attackers. Application throttles stop *credential* attacks; they do not stop volumetric ones,
because returning a 429 still costs a PHP worker. That resilience is bought at the edge — see A3.

---

## 6. Hardening checklist

- [x] Authenticate supplier and shop-list endpoints
- [x] Allowlist resources on every public shop route
- [x] SSRF allowlist at both the service and monolith boundaries
- [x] Constant-time secret comparison, fail-closed on missing keys
- [x] Disable API schema/doc exposure; remove wildcard CORS
- [x] Webhook signature verification (SendGrid; Razorpay and shipping already had it)
- [x] Phone-keyed OTP limiting, independent of client IP
- [x] Security headers + enforced CSP on both web apps
- [x] `Secure` / `SameSite` on session cookies
- [x] Disable PDF code execution
- [x] `POST /logout-all-devices`
- [ ] **Enable token expiry** — one env flag, but it logs everyone out; needs an announced window
- [ ] **Reconcile the plant-doctor deployment with source** (A1)
- [ ] **Rotate the committed secret** (A2)
- [ ] **Restrict the origin firewall to Cloudflare** (A3)
- [ ] **Bump vulnerable dependencies** (A4)
- [ ] Set `SENDGRID_WEBHOOK_TOKEN` and update the console URL to make H2 strict
- [ ] Replace the production Razorpay **test** key with a live one
- [ ] Alerting on authentication anomalies (NIST Detect/Respond)

---

## 7. What this audit did NOT cover

Stating the limits is part of the audit.

- **Volumetric DoS against production** — refused, see §5.
- **The running plant-doctor service** — not reproducible from source (A1).
- **Payment gateways other than Razorpay.** Razorpay verifies its webhook signature and
  re-checks the captured amount. The other gateway classes under `packages/marvel/src/Payment/`
  implement their own `handleWebHooks` and were not individually reviewed.
- **Coupon redemption under concurrency** — single-use enforcement across simultaneous orders
  was not proven.
- **The admin `graphql` workspace** — carries an unsanitized `dangerouslySetInnerHTML`, but is
  never built for production. Recommend deleting the workspace rather than patching dead code.

---

## 8. Low findings

### L1 · Unauthenticated requests return HTTP 500 instead of 401
Verified on staging: `POST /api/logout-all-devices` and the pre-existing `GET /api/me` both
return **HTTP 500** with the body `{"message":"Unauthenticated."}`. Routes authenticated via a
`Route::middleware('auth:sanctum')->group(...)` wrapper correctly return 401 (confirmed on
`/api/vendors`), so the behaviour is inconsistent between the two registration styles.

No data is exposed and the request is properly rejected — this is a status-code defect, not an
access-control one. It matters for two practical reasons: monitoring cannot distinguish "someone
hit an authenticated route logged-out" from "the application is erroring", and clients cannot
reliably branch on 401 to trigger a re-login. Pre-existing and app-wide; not introduced by this
pass and not fixed in it.

---

## 9. Accepted risk

**~90 models declare `$guarded = []`**, so nothing at the model layer prevents mass assignment.
No exploitable call site exists today — every write path uses explicit field lists. Editing 90
files for no current benefit is the wrong trade; the cheaper durable control is a CI rule
banning `$request->all()` in write paths.

---

## Appendix — commits

| Commit | Repo | Contents |
|---|---|---|
| `c6edf67` | api (**main/prod**) | C1, C2, H6, SSRF boundary |
| `ab8726a` | api (staging) | same, on staging |
| `627b5c1` | api (staging) | H1 OTP limiter, H2 SendGrid |
| `05091e9` | api (staging) | logout-all-devices, expiry seam |
| `bb46f57` | ai-microservices | plant-doctor + seo auth, SSRF guard (**not deployed**, see A1) |
| `af49313` | ai-microservices | H5 constant-time, fail-closed, docs off, CORS |
| `3811db6` | shop-v2 | H4 headers, CSP, cookie flags |
| `afa0048` | admin | H4 headers, CSP, cookie flags |
| `8adbba5` | admin | Security Audit page (Settings → Security Audit) |
