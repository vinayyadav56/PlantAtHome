# Database Report

> MySQL 8.0.42 · `pah_perf` · 1,570 products · 4,710 reviews · 6,000 orders · 17,975 line items
> `innodb_buffer_pool_size` = 128 MB, dataset ≈ 8 MB — everything fits in memory, so these
> figures isolate **query cost**, not disk I/O. Production, with a larger catalogue and a
> cold buffer pool, will be worse.

## Query benchmarks

200 iterations each over one persistent PDO connection.

> A first attempt shelled out to the `mysql` client per iteration and produced a flat ~23ms
> for every query — that was process spawn and TCP connect, not query time. Recorded here
> because the corrected method is the reason these numbers mean anything.

| Query | p50 | p95 | Rows |
|---|---:|---:|---:|
| SELECT by slug (indexed) | 0.520 ms | 4.550 ms | 1 |
| SELECT by type+status (indexed) | 0.938 ms | 6.241 ms | 20 |
| JOIN via category slug | 0.329 ms | 3.052 ms | 0 |
| COUNT published | 0.607 ms | 2.685 ms | 1 |
| Paginate page 1 | 0.192 ms | 1.901 ms | 20 |
| Paginate page 50 (offset 980) | 1.468 ms | 5.931 ms | 20 |
| LIKE `%term%` (full scan) | 1.890 ms | 9.120 ms | 17 |
| GROUP BY reviews | 0.309 ms | 3.628 ms | 20 |
| **best-selling join+group+sort** | **116.206 ms** | **215.562 ms** | 10 |
| translated_languages (single) | 0.355 ms | 2.551 ms | 1 |
| blocked_dates (`date()` non-sargable) | 0.233 ms | 1.209 ms | 0 |

Two things stand out.

**The best-sellers query is 60–600× everything else.** Its plan is `type: ALL` over
`products` with `Using temporary; Using filesort` — a full catalogue scan joined to
`order_product` and `orders`, grouped, then sorted on a derived column. Cost grows with both
catalogue size and order history, without bound, because there is no date restriction unless
`?range=` is passed. It only executes at all because `config/database.php` sets
`'strict' => false`, disabling `ONLY_FULL_GROUP_BY`.

**Offset pagination degrades as expected** — page 50 is 7.7× page 1. `limit` is clamped to
100 but `page` is not, so `?page=100000` is a legal request that walks the whole table.

## Indexes: what was missing

Verified against `information_schema.STATISTICS` on the live schema rather than the migration
history, because every earlier index migration wraps itself in `try/catch { /* ignore */ }` —
a silent failure leaves the index absent behind a green migration.

Absent before this pass:

| Table | Column(s) | Consequence |
|---|---|---|
| `categories` | `slug` | `type: ALL` over 193 rows, executed **100× per request** on `/api/categories` |
| `types` | `slug` | table had **only** a PRIMARY key, and is filtered inside correlated `whereHas` subqueries |
| `tags`, `attributes`, `attribute_values` | `slug` | all declared searchable in `ProductRepository::$fieldSearchable` |
| `shops` | `slug` | `whereRaw('LOWER(slug) = ?')` — non-sargable *and* unindexed |
| `reviews` | `(product_id, rating)` | backs the `rating_count` accessor's GROUP BY |
| `availabilities` | `(product_id, bookable_type)` | backs the `blocked_dates` accessor |

After adding them, `categories.slug` went from `type: ALL / 193 rows` to `type: ref / 1 row`,
and cold DB time fell 26.3 ms → 7.2 ms on `/api/categories` and 6.6 ms → 1.8 ms on `/api/types`.

`availabilities.to` is deliberately **not** in the new index: the query wraps it in `date()`,
which no index can serve. Making that sargable is a code change, not a schema one.

## Recommendations, in impact order

1. **Materialise best-sellers.** A scheduled rollup table, or at minimum a date bound plus a
   covering index on `order_product(product_id, order_quantity)`. At 116 ms p50 it is the
   single most expensive statement in the storefront.
2. **Move the cache off MySQL.** `CACHE_DRIVER=database` in production means every cache read,
   cache write and rate-limiter increment competes with the storefront on the same instance —
   measured at 13–15 cache reads per request.
3. **Replace `LIKE '%term%'` search.** A FULLTEXT index on `products(name, sku)` already exists
   and nothing uses it — there is no `MATCH … AGAINST` anywhere in the codebase.
4. **Bound `page`.** `limit` is clamped; `page` is not.
5. **Re-verify these indexes on production.** They are confirmed on a fresh migrate. Given the
   swallowed exceptions, that is not evidence they exist on a long-lived database.
