# Load & Stress Report

> Generated 2026-07-31T07:25:18.221Z · `perf/optimisation-pass` @ `9d391dc`
> **LOCAL ISOLATED BENCHMARK — not staging, not production**
> 1,570 products · 193 categories · 4,710 reviews · 6,000 orders · 17,975 line items

## Concurrency sweep (8 workers, warm cache)

| VUs | RPS | p50 | p95 | p99 | max | err % | generator CPU |
|---:|---:|---:|---:|---:|---:|---:|---:|
| 1 | 87.7 | 11.2 | 12.4 | 15.4 | 36.8 | 0 | 2.5% |
| 2 | 162.1 | 12.1 | 13.1 | 23.1 | 29.1 | 0 | 3.9% |
| 5 | 369.2 | 12.9 | 17.7 | 25.7 | 57 | 0 | 15.3% |
| 10 | 443.8 | 20.4 | 39.1 | 49.2 | 85.3 | 0 | 23.9% |
| 25 | 419.9 | 57.8 | 78.8 | 96.1 | 133.5 | 0 | 24.9% |
| 50 | 425 | 115.9 | 136.5 | 157.1 | 384.8 | 0 | 24.6% |
| 100 | 415.4 | 233.8 | 280.5 | 306.6 | 887.7 | 0 | 24.3% |
| 200 | 349.4 | 352.3 | 550.8 | 2358.1 | 6218.6 | 0.721 | 21.5% |
| 400 | 355.7 | 353.5 | 1430 | 5879.5 | 6219 | 5.09 | 23.5% |

Throughput peaks at **443.8 RPS around 10 concurrent** and then plateaus while latency grows linearly — queueing, not capacity. Errors appear at 200 VUs and reach 5.09% at 400. The generator never exceeded 24.9% of one core, so the knee is the server's, not the harness's.

## Throughput vs worker count

| Workers | RPS @ 50 VUs | p50 | p95 |
|---:|---:|---:|---:|
| 1 | 89.7 | 553.6 | 568 |
| 2 | 253.4 | 198.8 | 204.1 |
| 4 | 378.5 | 130.4 | 146.3 |
| 5 | 399.1 | 123.5 | 135.4 |
| 8 | 430 | 114.5 | 138.7 |
| 16 | 422.6 | 115.6 | 157.5 |

Scales with workers until it reaches the core count, then flattens — CPU-bound. Staging runs the stock php-fpm pool of 5.

## The framework floor

| Route | RPS @ 50 VUs | p50 | p95 |
|---:|---:|---:|---:|
| `/api/health` | 487.2 | 101.1 | 117.4 |
| `/api/settings` | 405 | 116.8 | 164.4 |
| `/api/popular-products` | 400.5 | 118.6 | 165.9 |
| `/api/products?limit=20` | 407.2 | 119.9 | 138.6 |
| `/api/products?limit=100` | 363.8 | 131.9 | 175.5 |

A trivial handler reaches **487.2 RPS**; the heaviest real endpoint reaches **363.8**. Every endpoint sits within 25% of the floor, so per-request overhead — not endpoint logic — sets throughput.
