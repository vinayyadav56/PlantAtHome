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
- Production runs php-fpm with cached config and opcache preload; per-request overhead there is likely lower than measured here.

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
