# Capacity Report

> Generated 2026-07-31T07:25:18.221Z · `perf/optimisation-pass` @ `9d391dc`
> **LOCAL ISOLATED BENCHMARK — not staging, not production**
> 1,570 products · 193 categories · 4,710 reviews · 6,000 orders · 17,975 line items

**MODELLED.** Derived from the measured constants via Little's Law. 100,000 concurrent users was never generated — this machine has 16,384 ephemeral ports.

## Measured constants

- `rps_per_core_measured` = 60.88
- `rps_per_core_heaviest` = 45.5
- `rps_per_core_typical` = 50.88
- `knee_vus` = 10
- `p50_at_knee_ms` = 20.4
- `bytes_products20` = 36454.4
- `bytes_products100` = 174592
- `bytes_settings` = 5222.4

## Assumptions (change these and re-run `capacity_model.mjs`)

- `think_time_s` = 30
- `api_calls_per_page` = 4
- `cdn_offload_anonymous` = 0.8
- `cart_poll_interval_s` = 7
- `authenticated_share` = 0.35
- `cache_hit_rate` = 0.9
- `prod_efficiency_factor` = 1
- `target_utilisation` = 0.65

## Projection

| Users | Edge RPS | Origin RPS | Cart poll | vCPU | Instances | Database | TB/mo | $/mo |
|---:|---:|---:|---:|---:|---:|---|---:|---:|
| 10,000 | 1,833 | 767 | 500 (65%) | 24 | 12 | r7g.large | 157.6 | $18,628 |
| 25,000 | 4,583 | 1,917 | 1,250 (65%) | 58 | 29 | r7g.2xlarge | 393.9 | $46,499 |
| 50,000 | 9,167 | 3,833 | 2,500 (65%) | 116 | 58 | r7g.2xlarge | 787.8 | $92,139 |
| 75,000 | 13,750 | 5,750 | 3,750 (65%) | 174 | 87 | r7g.2xlarge +1 replica | 1181.6 | $138,457 |
| 100,000 | 18,333 | 7,667 | 5,000 (65%) | 232 | 116 | r7g.2xlarge +1 replica | 1575.5 | $184,097 |
| 200,000 | 36,667 | 15,333 | 10,000 (65%) | 464 | 232 | r7g.2xlarge +3 replica | 3151.1 | $368,013 |
| 500,000 | 91,667 | 38,333 | 25,000 (65%) | 1,160 | 580 | r7g.2xlarge +9 replica | 7877.6 | $919,762 |
| 1,000,000 | 183,333 | 76,667 | 50,000 (65%) | 2,320 | 1,160 | r7g.2xlarge +19 replica | 15755.3 | $1,839,342 |

At 100,000 concurrent users, **65% of origin traffic is the 7-second cart poll** — uncacheable because it carries a bearer token. Removing it cuts the app tier by ~65%.

## Roadmap

1. **PM2 cluster mode (pm2 start -i max) for shop and admin** *(minutes)* — One flag. Today a single core serves all SSR and all proxied API traffic per app. Unblocks: Linear scale to the box core count
2. **Replace the 7s cart poll with on-demand fetch or a push channel** *(days)* — Modelled at 65% of origin RPS at 100k users, and it is uncacheable. Unblocks: ~65% reduction in app tier
3. **Install Redis and move CACHE_DRIVER off database** *(hours)* — 13-15 MySQL round trips per request just for cache reads, on the storefront database. Unblocks: Removes cache load from the primary DB
4. **Put a CDN in front of the public read API** *(days)* — The API already emits s-maxage=300; the model is most sensitive to this single factor (232 cores at 80% offload vs 555 at 0%). Unblocks: Largest single lever in the model
5. **Horizontal scale behind an ALB with autoscaling** *(weeks)* — Single-box deployment has no failover and no elasticity. Unblocks: Beyond one machine
6. **Reduce per-request overhead: opcache preload, then evaluate Octane** *(weeks)* — Measured as the dominant per-request cost; query tuning cannot move throughput past it. Unblocks: Raises RPS/core, lowering every row of the capacity table
