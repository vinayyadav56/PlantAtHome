# Performance Report

> Generated 2026-07-31T07:25:18.221Z · `perf/optimisation-pass` @ `9d391dc`
> **LOCAL ISOLATED BENCHMARK — not staging, not production**
> 1,570 products · 193 categories · 4,710 reviews · 6,000 orders · 17,975 line items

Per-endpoint cost, measured by dispatching real requests through the HTTP kernel with a `DB::listen` hook. A *duplicate* is the same SQL statement executed more than once in a single request — the N+1 signature.

| Endpoint | Payload | Cold q | dup | Cold ms | DB ms | Warm q | Auth q |
|---|---:|---:|---:|---:|---:|---:|---:|
| `settings` | 5.1 KB | 3 | 0 | 2.5 | 2.49 | 1 | 3 |
| `types` | 1.3 KB | 33 | 24 | 6.69 | 6.61 | 3 | 13 |
| `categories.root` | 57.9 KB | 108 | 100 | 27.26 | 26.26 | 2 | 108 |
| `categories.home` | 0.3 KB | 4 | 1 | 1.23 | 0.49 | 2 | 4 |
| `products.list.20` | 35.6 KB | 65 | 51 | 13.46 | 5.85 | 2 | 62 |
| `products.list.100` | 170.5 KB | 225 | 211 | 50.09 | 20.6 | 2 | 222 |
| `products.by_type` | 35.7 KB | 65 | 51 | 14.02 | 6.33 | 2 | 62 |
| `products.page50` | 34.9 KB | 65 | 51 | 15.37 | 7.56 | 2 | 62 |
| `products.facets` | 1.3 KB | 33 | 17 | 21.56 | 16.91 | 8 | 31 |
| `popular-products` | 23.7 KB | 111 | 95 | 66.31 | 29.49 | 105 | 139 |
| `best-selling` | 23.7 KB | 119 | 104 | 94.97 | 83.97 | 114 | 150 |
| `top-rated` | 24.7 KB | 137 | 122 | 102.86 | 16.42 | 132 | 174 |
| `product.show` | 5.6 KB | 44 | 20 | 8.4 | 3.65 | 2 | 48 |

## Worst duplicate statements

**`categories.root`** — 108 queries, 100 wasted

- `100x` `select * from `categories` where `slug` = ?`
- `2x` `select * from `cache` where `key` = ? limit 1`

**`products.list.20`** — 65 queries, 51 wasted

- `20x` `select * from `products` where `slug` = ? and `products`.`deleted_at` is null`
- `20x` `select * from `products_meta` where `product_id` = ? and `product_id` is not null`
- `13x` `select * from `cache` where `key` = ? limit 1`

**`products.list.100`** — 225 queries, 211 wasted

- `100x` `select * from `products` where `slug` = ? and `products`.`deleted_at` is null`
- `100x` `select * from `products_meta` where `product_id` = ? and `product_id` is not null`
- `13x` `select * from `cache` where `key` = ? limit 1`

**`products.by_type`** — 65 queries, 51 wasted

- `20x` `select * from `products` where `slug` = ? and `products`.`deleted_at` is null`
- `20x` `select * from `products_meta` where `product_id` = ? and `product_id` is not null`
- `13x` `select * from `cache` where `key` = ? limit 1`

**`products.page50`** — 65 queries, 51 wasted

- `20x` `select * from `products` where `slug` = ? and `products`.`deleted_at` is null`
- `20x` `select * from `products_meta` where `product_id` = ? and `product_id` is not null`
- `13x` `select * from `cache` where `key` = ? limit 1`

**`popular-products`** — 111 queries, 95 wasted

- `20x` `select * from `availabilities` where `product_id` = ? and `bookable_type` = ? and date(`to`) >= ?`
- `11x` `select count(*) as aggregate from `feedbacks` where `feedbacks`.`model_type` = ? and `feedbacks`.`model_id` is`
- `11x` `select count(*) as aggregate from `feedbacks` where `feedbacks`.`model_type` = ? and `feedbacks`.`model_id` is`

**`best-selling`** — 119 queries, 104 wasted

- `20x` `select * from `availabilities` where `product_id` = ? and `bookable_type` = ? and date(`to`) >= ?`
- `14x` `select count(*) as aggregate from `feedbacks` where `feedbacks`.`model_type` = ? and `feedbacks`.`model_id` is`
- `14x` `select count(*) as aggregate from `feedbacks` where `feedbacks`.`model_type` = ? and `feedbacks`.`model_id` is`

**`top-rated`** — 137 queries, 122 wasted

- `20x` `select * from `availabilities` where `product_id` = ? and `bookable_type` = ? and date(`to`) >= ?`
- `20x` `select count(*) as aggregate from `feedbacks` where `feedbacks`.`model_type` = ? and `feedbacks`.`model_id` is`
- `20x` `select count(*) as aggregate from `feedbacks` where `feedbacks`.`model_type` = ? and `feedbacks`.`model_id` is`

