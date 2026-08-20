# PlantAtHome — Production Readiness & Operations

Supersedes the RBAC-scoped `api/PRODUCTION_READINESS.md` (2026-06). Live handover detail lives in
`api/OPERATOR_RUNBOOK_2026-08.md`; this is the enterprise-audit operational picture.

## Deploy & rollback

| Repo | Staging | Production | Rollback |
|---|---|---|---|
| API (this repo) | `git push origin HEAD:staging` (Railway) | `gh workflow run production.yml -f branch=main -f confirm=deploy-production` (EC2, gated on `php artisan test`) | `git revert <sha>` → re-dispatch; each pass commit is independently revertible |
| Storefront `plantathome-shop-v2` | push `shop` remote `staging` (Vercel) | push `shop` `main` + `production.yml` dispatch | `git revert` |
| Admin `PlantAtHomeAdmin` | push `staging` branch | `production.yml` dispatch | `git revert` |

Tests locally: `vendor/bin/phpunit -d memory_limit=2G` (artisan test OOMs). New class in the marvel
classmap ⇒ `composer dump-autoload -o` first.

## Environment matrix (the trap: unsafe code defaults)

| Var | Code default | Prod (production.yml) | Staging | Note |
|---|---|---|---|---|
| CACHE_DRIVER/STORE | file | **redis** (+ REDIS_CLIENT=phpredis, HOST 127.0.0.1) | file | phpredis mandatory — predis measured slower than MySQL |
| SESSION_DRIVER | file | **redis** (added 2026-08-10) | file | was unset → file; instance-local state |
| QUEUE_CONNECTION | **sync** | database | sync→database (one-shot workflow) | sync = jobs run inline; a bare box is silently degraded |
| FILESYSTEM_DISK / MEDIA_DISK | local | s3 / s3 | s3 | local only a dev hazard |
| INVENTORY_OVERSELL_POLICY | log | log (flip to `block` after data check) | flip here first | see §Inventory |
| STALE_UNPAID_ORDER_HOURS | 24 | 24 | 24 | sweep threshold |
| WEBHOOK_GATEWAYS | razorpay,stripe,paypal | set to `razorpay` | — | unlisted gateways 404 |

⚠️ A new instance provisioned without the full env set comes up with sync queue + file sessions
while `/health` still 200s. `/health/ready` probes DB+cache but NOT queue/session — treat the env
matrix as part of provisioning (roadmap F-18).

## Health & monitoring

- `/health/live` — liveness, dependency-free (LB "is the process up").
- `/health/ready` — DB + cache round-trip, 200/503 (LB "drain vs serve"). Does not probe queue/session.
- Mission Control / `/live-activity` NOC / `/setup/logs`; hourly integration health; platform heartbeats.
- No APM. Watch queue depth via `platform/status`; failed jobs in the `failed_jobs` table.

## Inventory oversell flip (P0 remediation activation)

1. On staging set `INVENTORY_OVERSELL_POLICY=block`; run a real add-to-cart→checkout on an in-stock
   and an out-of-stock product (expect 200 / 422).
2. Data check before prod: count published, purchasable products with `quantity <= 0`
   (`SELECT count(*) FROM products WHERE status='publish' AND quantity <= 0 AND product_type != 'bundle'`).
   The 2026 catalog import left ~2.6k drafts; if a meaningful number of LIVE products have unmaintained
   0 counters, fix the data (or keep `log`) before flipping — blocking must not brick real checkout.
3. Flip prod env → redeploy. Watch order 422 rate + the `Inventory oversell detected` log line
   (now rare, since it blocks).

## Concurrency guarantees (what's proven)

- **Coupons**: `usage_limit=1` ⇒ at most 1 redemption (row lock + UNIQUE `coupon_usages` ledger;
  100-attempt test). Failed payment / stale sweep release the slot.
- **Inventory** (block policy): qty=1 + N buyers ⇒ exactly 1 order; never negative; all-or-nothing
  across cart lines (transaction rollback).
- **Checkout**: same Idempotency-Key ⇒ one order (unique index + race catch).
- **Payment webhooks**: replays idempotent (state guard); signature + amount verified; 24h sweep
  reclaims abandoned holds.

## Plant Doctor — reproducible deploy + H7 reconciliation

Source: `/Users/vinayyadav/PlantAtHome/plant-doctor-service` (FastAPI, port 8003; pinned
`requirements.txt`; `Dockerfile` python:3.12-slim; SSRF guard + test; docs/CORS default-off).
Env: `ANTHROPIC_API_KEY`, `PLANT_DOCTOR_API_KEY` (matches monolith
`services.plant_doctor.api_key`), `CORS_ORIGINS`, `ENABLE_API_DOCS=false`.

Reproducible deploy: `docker build -t plant-doctor . && docker run -p 8003:8003 --env-file .env`.
Health: `GET /health`.

**H7 — the deployed instance is NOT built from this source** (2026-08 pentest, in writing: a redeploy
from `main` "would crash it" — its auth posture/provider/config diverge). Reconciliation, in a window:
(1) capture the running service's env + a request/response sample; (2) diff behavior against this
source; (3) port any genuine divergence into the repo (PR); (4) redeploy from the repo image; (5)
smoke `/health` + one diagnosis; (6) keep the old instance warm for instant rollback. Do NOT blind-redeploy.

## Known operator items (carry-over)

Razorpay LIVE key (prod is `rzp_test_` — no real payment has run); rotate the `chatbot-service`
committed secret (H8); Cloudflare-range origin firewall (A3); managed Redis + ALB + RDS for
multi-instance; opcache-preload segfault on the PHP 8.1 box; delete `admin/graphql`.
