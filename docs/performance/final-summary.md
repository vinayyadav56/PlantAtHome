# Final Summary

> Generated 2026-07-31T07:25:18.221Z · `perf/optimisation-pass` @ `9d391dc`
> **LOCAL ISOLATED BENCHMARK — not staging, not production**
> 1,570 products · 193 categories · 4,710 reviews · 6,000 orders · 17,975 line items

## Findings

### [CRITICAL] Production is a 2-core / 1,910 MB box running everything

Read off the host during the promotion, not modelled. Every capacity number in
these reports was produced without it, because nothing in the repo records the
instance size — the AWS report says as much ("`pm.max_children` not in the repo
— read it off the box").

That single box carries the storefront, the admin, php-fpm, six systemd queue
workers and nginx, in **1.9 GB of RAM across 2 cores**.

Two consequences that change how the rest of this document should be read:

1. **The 4.03× cluster-mode gain does not transfer.** It was measured on an
   8-core machine. This host has room for **one** storefront worker, so cluster
   mode here buys genuinely zero-downtime reloads, not throughput. The
   ecosystem files size themselves from `os.cpus()` / `os.totalmem()` on the
   target rather than assuming; on this box that resolves to 1 worker for the
   shop and 1 for the admin. Had it still said `instances: 'max'` the deploy
   would have started 2 workers with a 900 MB ceiling each on a 1.9 GB box.
2. **This is the binding constraint, not the code.** Every optimisation in this
   pass reduces work per request, but the app tier cannot scale past 2 cores on
   one instance. The cheapest real capacity win available is not in this
   repository — it is a larger instance, or a second one behind a load
   balancer.

### [CRITICAL] Production runs one Node process per app, on one box

The storefront and admin each start under PM2 in fork mode with no -i flag, so SSR and the /rest-api proxy share a single CPU core per app. No amount of query tuning changes this ceiling.

`plantathome-shop-v2/.github/workflows/production.yml:212`

### [CRITICAL] The 7-second cart poll is 65% of modelled origin traffic at 100k users

Every authenticated user polls /me/cart every 7s. It carries a bearer token, so it is uncacheable at every layer and all of it reaches the origin. Modelled at 100k concurrent: 5,000 of 7,667 origin RPS. Removing it cuts the required app tier by 65%.

`plantathome-shop-v2/src/store/quick-cart/cart.context.tsx:158`

### [HIGH] Per-request framework overhead dominates, not endpoint logic

A trivial handler sustains 487 RPS on 8 workers while the heaviest real endpoint sustains 364 — every endpoint is within 25% of the floor. Optimising queries cuts database load but will not raise request throughput until per-request overhead falls.

`measured: floor comparison, 50 VUs, 8 workers`

### [HIGH] Cached feed endpoints ran 105-132 queries on a cache HIT

popular-products, best-selling and top-rated cached Eloquent models, so every append re-fired when the cached value was rehydrated. Fixed by serialising before caching; a hit now costs 2 queries. Verified byte-identical responses.

`ProductController::popularProducts / bestSellingProducts / topRatedProducts`

### [HIGH] Redis is configured but not installed

config/database.php and config/cache.php define Redis stores, but no Redis client is installed and production sets CACHE_DRIVER=database. Every cache read and every rate-limiter increment is a MySQL round trip on the same database serving the storefront — measured at 13-15 cache reads per request.

`api/Dockerfile:19 omits the redis extension; production.yml:231`

### [MEDIUM] Slug columns were unindexed on every lookup table

categories.slug, types.slug, tags.slug, attributes.slug, attribute_values.slug and shops.slug had no index. GET /api/categories performed 100 full scans of the categories table in a single request. Indexes added; cold DB time fell 26.3ms to 7.2ms.

`verified against information_schema, not migration history`

## How to read these numbers

- Absolute throughput is from a laptop CLI server, not php-fpm on EC2. Treat RPS/core as a relative constant for modelling, not as a production SLA.
- The dataset is 8 MB and fits entirely in the InnoDB buffer pool, so these numbers isolate query and framework cost, not disk I/O.
- Everything above the measured saturation point is MODELLED, not observed. 100k concurrent users was never generated — this machine has 16,384 ephemeral ports.
- Production runs php-fpm with cached config. It does **not** run opcache preload, and until this pass
  opcache had no configuration at all — that sentence previously claimed otherwise and was wrong.
  Per-request overhead on production is therefore unlikely to be materially lower than measured here,
  and the host is smaller than this laptop (2 cores vs 8).

## Final scorecard

| | Measured | Where |
|---|---|---|
| Saturation throughput | 444 RPS at 10 VUs, 8 workers | local rig |
| Per-core throughput | ~61 RPS/core (~16 ms fixed cost/request) | local rig |
| Latency at saturation | p50 105 ms · p95 123 ms · p99 164 ms | local rig |
| Framework floor | 487 RPS trivial handler vs 364 heaviest endpoint | local rig |
| Queries per request (feeds) | 105–132 → **2** on a cache hit | local rig |
| Memory stability | RSS −0.2 MB/min over 35 min, 450,573 requests, 0 errors | local rig |
| Overload behaviour | sheds at the accept queue; 0 5xx; recovers in 1–2 s | local rig |
| **Response bytes** | **180,465 → 13,618 (−92.5%)** | **production** |
| **Conditional requests** | **HTTP 304 on products/types/categories** | **production** |
| **Storefront eager JS** | **2,034,939 → 1,632,528 B (−19.8%)** | **production** |
| **Production host** | **2 cores / 1910 MB, 1341 MB available, worker RSS 175 MB** | **production** |
| Cache hit ratio | not measured — Redis is deliberately inert on production | — |
| Cost | unchanged; no instance was resized | — |
| Max sustainable concurrency | bounded by 2 cores shared with php-fpm; not load-tested on production by instruction | — |

### The five deferred items, now measured

Each was taken to a measurement rather than left as an opinion. Two produced
action, two are null results that were deliberately **not** shipped, and one
rested on a premise of mine that turned out to be wrong.

| Item | Verdict |
|---|---|
| opcache preload | Local A/B **+3.3%, p95 −20%, won 7/8 rounds** — but the deploy's dry run caught a **segfault** on the production PHP build and left it **OFF**. The guard prevented a total outage. |
| 81 service providers | **No effect.** Dropping 19 moved the warm median +0.344 ms, winning 4/6 rounds. Not shipped. |
| 48 raw `<img>` tags | **Wrong premise.** Not one shifting element is an `<img>`. Real cause fixed: production CLS **0.887 → 0.383**. |
| Redis | **Slower: −3.0%** on the only client installed anywhere (predis, pure PHP). Not enabled. |
| k6 ladder to 50k | **Ceiling measured: 16,340** concurrent, `EADDRNOTAVAIL` — within 0.3% of the predicted 16,384. |

Details, including the exact numbers and why each verdict went the way it did,
are in `production-verified.json` and on the admin report page.

### What genuinely remains

- **A bigger box, or a second one behind a load balancer.** Still the single
  largest constraint, and the only one not addressable in this repository. The
  app tier cannot scale past 2 cores on one instance.
- **The residual CLS of 0.383 on search.** Down from 0.887, but still above the
  0.1 "good" threshold, and 58 other `dynamic(ssr:false)` call sites lack a
  loading fallback. Worth working per-page, measured the same way.
- **Redis with the C client.** `config/database.php` defaults to `phpredis`,
  which is installed nowhere. Install `php8.1-redis` and re-measure before
  touching `CACHE_DRIVER` — the pure-PHP client is measurably worse than the
  MySQL cache it would replace.
- **opcache preload on Ubuntu.** The machinery is in place and self-enabling;
  it needs the segfault diagnosed on a like-for-like build, not on production.
- **Image delivery.** Still worth doing, but for bandwidth rather than CLS:
  resize-at-upload plus a CDN. `next/image` remains the wrong tool here — it
  would spend the CPU that is already the bottleneck.

## Reproducing

```bash
# baseline
php tests/Performance/bench.php --env=perf --iterations=5 --json=docs/performance/baseline-cold-anon.json
# throughput
node tests/Performance/loadgen.mjs --url=... --vus=50 --duration=10
# capacity model
node tests/Performance/capacity_model.mjs
# rebuild the admin report
node tests/Performance/build_report.mjs > ../admin/rest/public/performance-report.json
```

## Failure mode under overload

Measured at 500 concurrent: **zero** failures were error responses — all 564 were `ETIMEDOUT` at the
connection layer. The application does not return 5xx under overload, does not accumulate an unbounded
queue, and recovers to its baseline latency within 1–2 seconds of the load being removed.

Capacity is therefore bounded, but the boundary is graceful: excess load is shed at the edge of the
accept queue rather than degrading everyone already inside. Worth knowing before choosing an
autoscaling policy — the system tolerates brief overshoot well.
