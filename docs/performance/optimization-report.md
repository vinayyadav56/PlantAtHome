# Optimisation Report

> Generated 2026-07-31T07:25:18.221Z · `perf/optimisation-pass` @ `9d391dc`
> **LOCAL ISOLATED BENCHMARK — not staging, not production**
> 1,570 products · 193 categories · 4,710 reviews · 6,000 orders · 17,975 line items

## Applied

1. **Slug and lookup indexes** — `categories.slug`, `types.slug`, `tags.slug`, `attributes.slug`, `attribute_values.slug`, `shops.slug`, `reviews(product_id,rating)`, `availabilities(product_id,bookable_type)`. Verified absent against `information_schema`, not migration history.
2. **`translated_languages`** — plucks one column instead of hydrating full models, and memoises per instance.
3. **Feed endpoints serialise before caching** — `Cache::remember` was storing Eloquent models, so appends re-fired on every cache hit.

## Measured effect

| Endpoint | Warm queries | Warm ms |
|---|---|---|
| `popular-products` | 105 → **2** | 14.38 → **0.54** |
| `best-selling` | 114 → **2** | 15.47 → **0.62** |
| `top-rated` | 132 → **2** | 17.52 → **0.63** |

## The A/B that did not help

`/api/popular-products?limit=10` — 50 VUs, 8 workers, opcache on, database cache warm, identical dataset

| | RPS | p50 | p95 | p99 |
|---|---:|---:|---:|---:|
| before | 464.3 | 105.4 | 123 | 163.7 |
| after | 448.2 | 108.6 | 128.3 | 233.3 |

No throughput gain (-3.5% RPS, within run-to-run noise). Reported as measured. The handler-level improvement is real (105 to 2 queries, 14.4ms to 0.54ms) but is masked by framework overhead that dominates every request on this rig.
