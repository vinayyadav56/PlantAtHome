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

## Verified on production

Measured against `api.plantathome.in` after the promotion, not inferred.

### Response compression — the largest single win

nginx had no `gzip` and there was no Laravel middleware, so every JSON response
went out uncompressed.

| Endpoint | Identity | gzip | Saved |
|---|---:|---:|---:|
| `settings` | 5,710 | 2,347 | 58.9% |
| `types?limit=10` | 4,237 | 817 | 80.7% |
| `categories?parent=null` | 9,393 | 1,268 | 86.5% |
| `products?limit=20` | 19,410 | 1,294 | 93.3% |
| `products?limit=100` | 93,200 | 3,489 | 96.3% |
| `popular-products?limit=10` | 48,515 | 4,403 | 90.9% |
| **Total** | **180,465** | **13,618** | **92.5%** |

### Conditional requests

`cache.headers:etag` on the public read routes. `products`, `types` and
`categories` all return a weak ETag and answer **HTTP 304** to a matching
`If-None-Match`, so a repeat request costs headers instead of a body.

### Storefront bundle

The `agentation` dev toolbar was imported statically, so it shipped in the
eager chunk graph on every route despite a hostname gate that stops it ever
rendering for a customer. Measured on served HTML before and after:

| | Eager JS on home | of which agentation |
|---|---:|---:|
| before | 2,034,939 B | 443,873 B (21.8%) |
| after | 1,632,984 B | **0 B** |

**−401,955 bytes (−19.8%)** of eagerly-loaded JavaScript. The toolbar still
mounts on staging (verified in a real browser: its DOM nodes are present), it
is simply fetched as an async chunk that production never requests.

## Applied but reverted, with the measurement that killed it

**`opcache.validate_timestamps=0`.** Justified as "saves a `stat()` per file per
request". That reasoning was wrong: opcache re-stats a file at most once per
`opcache.revalidate_freq` seconds, default **2**, so under load the cost
amortises to approximately nothing.

Measured with an interleaved A/B — 8 rounds × 220 requests, arms alternated each
round so host drift cancels, paired per-round deltas, medians not means, because
this host produced a 6.7× swing between two runs of an *identical* config:

| | median | p95 | n |
|---|---:|---:|---:|
| `validate_timestamps=1` | 10.663 ms | 11.604 ms | 1,760 |
| `validate_timestamps=0` | 10.737 ms | 11.646 ms | 1,760 |

Median paired delta **−0.084 ms (−0.8%)** — if anything slower — and `=0` won
only **2 of 8** rounds, indistinguishable from a coin flip. Removed: no
measurable gain, and it carries a failure mode where any deploy that fails to
cycle php-fpm serves the previous build indefinitely while health checks stay
green.

Kept from that change, because these *are* motivated by measurement:
`interned_strings_buffer=32` (was 100% full at 8MB of 8MB, past which opcache
stops interning), `max_accelerated_files=20000` (default 10,000 is below this
app's 1,547 app + 18,411 vendor files), `memory_consumption=256`.
