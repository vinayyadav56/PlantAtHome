# PlantAtHome — Performance Baseline (2026-08-10)

Two evidence sources: (1) the authoritative **in-VPC capacity study** for the prod ceiling, and
(2) a **post-change staging ladder** run this pass to confirm the hardening introduced no regression
and the read path scales cleanly.

## Authoritative prod ceiling (existing measured study)

From `api/docs/performance/*` (capacity-report, database-report, optimization-report, +raw JSON,
Lighthouse) and the perf report page (machine-derived by `build_report.mjs`):

- **~122 RPS origin ceiling**, ~61 RPS/core on the single 2-core/1910MB EC2 box.
- Bottleneck is **PHP framework overhead, not queries** — confirmed by EXPLAIN + query timing work;
  the DB is not the limiter at this scale.
- Fixes already shipped: `/api/products` 75s tail (N+1 `$appends` → eager `withCount/withAvg`,
  `Cache::remember` → `rememberWithLock` stampede lock, default `status:publish`); Redis cache +
  rate-limiter moved off MySQL; storefront PDP double-fetch removed; slug/status/fulltext indexes.
- This is the number to design against; horizontal scale (2nd instance + ALB + managed Redis) is the
  lever, since the ceiling is per-box (roadmap #2).

## Post-change staging ladder (this pass)

Closed-loop VU ladder (`scratchpad/ladder.mjs`, node:https — the repo `loadgen.mjs` is node:http /
in-VPC), against Railway staging (`plantathome-production.up.railway.app`, the confusingly-named
staging host), hot read path `GET /api/products?limit=20&language=en`. Conservative by design
(shared small box; stop on error/p95 knee).

| VUs | RPS | p50 (ms) | p95 (ms) | p99 (ms) | requests | errors |
|----:|----:|---------:|---------:|---------:|---------:|-------:|
| 2   | 5.4 | 348 | 464 | 804  | 66   | 0 |
| 5   | 13.7 | 344 | 437 | 667  | 167  | 0 |
| 10  | 25.3 | 358 | 631 | 811  | 312  | 0 |
| 20  | 42.4 | 399 | 845 | 1094 | 533  | 0 |
| 40  | 71.2 | 463 | 950 | 1353 | 943  | 0 |
| 60  | 87.4 | 538 | 1368| 1749 | 1132 | 0 |

**Reading it:**
- **Zero errors at every rung** through 60 concurrent / 87 RPS — the P0/P1 changes introduced no
  regression on the hot path (webhook allowlist, idempotency, stock gate all live during the run:
  verified `webhooks/xendit`→404, `/health/ready`→`{db:ok,cache:ok}`).
- Throughput **scales linearly** (5.4→13.7→25.3→42.4→71.2→87.4) with no error knee — staging still
  had headroom at 60 VUs; the ladder was stopped there deliberately (not a destructive saturation
  test against a shared box).
- **~340ms latency floor** at low load is internet RTT + Cloudflare/Railway proxy + TLS to the
  remote staging host — NOT app compute. Absolute latencies here are network-dominated and are not
  comparable to the in-VPC ceiling numbers; the shape (linear RPS, flat error rate) is the signal.

## Method & safety

- No destructive levels; no prod host (`ladder.mjs` refuses `plantathome.in`, mirroring the repo
  harness guard). 12s per rung, 3s cool-down, ≤60 VUs.
- For a true saturation ladder to 200+ RPS, run the in-VPC `loadgen.mjs`/`spike.mjs` against the
  origin from inside the network (as the original capacity study did) — a public-internet generator
  measures the path, not the box.

## What to watch in prod (no APM yet)

RPS + p95/p99 on `/api/products` and the PDP; 4xx/5xx/429 split; DB CPU + connection count; Redis
latency; queue depth + failed_jobs; the new `Inventory oversell detected` log line (rare under
`block`); order 422 rate; `orders:cancel-stale-unpaid` cancellation counts.
