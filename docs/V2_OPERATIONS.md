# Backend V2 (`/api/v1`) — Operations Runbook

_Last updated: 2026-07-14 (v2.1.0 production release)._

## What V2 is

The modular-monolith platform core: **14 bounded contexts** under `app/Modules/`
(Identity, Catalog, Configuration, Rules, Inventory, Pricing, Serviceability,
Sales, Search, Promotions, Notifications, CMS, Analytics, Platform) served
under **`/api/v1`** beside the legacy marvel API (`/api`). V2 is **fully
additive**: every table is context-prefixed (`catalog_*`, `sales_*`, `inv_*`, …)
and no legacy table or route is touched. There are no feature flags — V2 is
always-on wherever the code is deployed.

Auth is a custom HS256 JWT (Identity module): `v1.auth`, `v1.auth.optional`,
`v1.role:*`, `v1.can:*`, `v1.nursery` middleware. Full endpoint reference lives
in the admin panel → Platform Management → **API Documentation** (V2 Platform
tag group); release history in **Version Documentation**.

## Health & observability

| Endpoint | Auth | Purpose |
|---|---|---|
| `GET /api/v1/health` | public | DB connectivity + env + coarse `scheduler_beat_age_seconds` — load-balancer probe that also exposes a dead cron loop |
| `GET /api/v1/platform/status` | admin/super_admin JWT | outbox backlog + oldest-pending age, queue depth, failed jobs (24 h), scheduler heartbeat staleness, single `healthy` verdict |
| `POST /api/v1/platform/ping` | public | writes a demo event through the transactional outbox — end-to-end async smoke |

`healthy: false` means either the scheduler heartbeat is older than 180 s (cron
loop dead) or the oldest pending outbox row is older than 300 s (relay stalled).

## Async machinery (must be running for V2 to function)

The Kernel schedule (`app/Console/Kernel.php`) runs every minute:
`outbox:relay --once` (domain-event delivery), `inventory:release-expired`
(abandoned-checkout stock return), the `platform-heartbeat` write, plus the
daily marvel settlement/reconcile jobs.

| Environment | Scheduler | Queue workers |
|---|---|---|
| **Staging (Railway)** | `while true; php artisan schedule:run; sleep 60` loop in `.railway/start.sh` | supervisord: `queue:work` on `default` + `careplans` |
| **Production (EC2)** | `www-data` crontab: `* * * * * php artisan schedule:run` (installed idempotently by production.yml) | systemd `plantathome-queue@default` + `plantathome-queue@careplans` (User=www-data, Restart=always; restarted on every deploy to pick up new code) |

Everything runs as **www-data** on EC2 — `storage/` is www-data-owned and an
ubuntu-run artisan dies on log writes (long-standing gotcha).

Prod worker ops (SSH): `sudo systemctl status plantathome-queue@default`,
`journalctl -u plantathome-queue@default -n 100`, `sudo crontab -l -u www-data`.

## Deployment

- **Staging**: push to `staging` → CI (gitleaks/audit/Trivy → **full PHPUnit
  suite, ~164 tests, MySQL e2e** → Railway auto-build) → health poll.
- **Production**: `gh workflow run production.yml -f branch=main -f confirm=deploy-production`
  → same gates → SSH deploy (composer, `migrate --force`, idempotent seeders,
  cron/worker provisioning, config cache, php-fpm reload) → health check.
- CI runs the test suite since v2.1.0; a red suite blocks both environments.

## Users on production

Production seeds the v2 RBAC baseline only — **no demo users** and no public
registration. Mint the first real admin on the box:

```bash
php artisan v2:make-admin ops@plantathome.in --role=super_admin
# generated password is printed once; login via POST /api/v1/auth/login
```

## Environment variables

| Var | Notes |
|---|---|
| `IDENTITY_JWT_SECRET` | HS256 signing secret. **Falls back to `APP_KEY`** — works, but set an explicit value so key rotation doesn't invalidate sessions. |
| `QUEUE_CONNECTION` | `database` on both environments (workers consume the `jobs` table). |
| `CACHE_DRIVER` | staging runs `array` (container file-cache dir was unwritable — writes 500'd AFTER committing); prod uses `file`. |

## Rollback

V2 is additive, so rollback is code-only:

1. `git revert` (or reset) the offending commit on `main` → re-dispatch
   `production.yml`. Prefixed V2 tables stay in place and are inert without the
   code — no schema rollback needed or wanted.
2. Cron entry + systemd workers are harmless when idle; remove manually only if
   decommissioning V2 entirely (`sudo crontab -r -u www-data`,
   `sudo systemctl disable --now 'plantathome-queue@*'`).

## Load sanity (Phase 12)

`node tests/Load/v2_hot_reads_loadtest.mjs [base]` — 50 concurrent for 30 s per
hot public read. Budget self-calibrates: **p95 < measured RTT baseline + 500 ms
server allowance, zero 5xx, ≤0.5 % transport errors** (a fixed wall-clock budget
from an arbitrary client would punish network distance, not the service).

| Date | Base | Result |
|---|---|---|
| 2026-07-14 | staging (Railway, client RTT ~400–500 ms) | **PASS** — throughput 91–136 rps/endpoint at 50-concurrency, zero 5xx. p95: health 504 ms · categories 727 ms · search 769 ms · banners 510 ms · products 949 ms (heaviest read: variants+media eager-load; ≈450–500 ms server-side). v2.1.0 release run. |
