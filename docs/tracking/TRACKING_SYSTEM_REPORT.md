# PlantAtHome visitor tracking — human / bot / unknown

Implemented 2026-10-07. API `794d350`…`02e4497`, storefront `65f7f5b`…`55ddf54`, admin `38c4ca9`.

## 1. Summary

The existing tracker (SDK → `POST /api/track` → `visitors` + `analytics_events` → `VisitorMetricsService` → admin) was kept and productionised:

- every visitor, session and event is classified **human / bot / unknown** and stored with that label — bots are separated, never discarded;
- **sessions** are first-class (`analytics_sessions`, 30-min inactivity rule) with an **engaged** flag and per-session UTM;
- a **heartbeat** (30 s, visible tabs only) and **one** definition of *online* (120 s) replace three disagreeing counts;
- **non-JS crawlers** are seen through a server-side leg (`proxy.ts` → `POST /api/track/crawl`);
- the purchase **funnel is human-only** and its last step now actually fires (it never had);
- a **bot analytics** report (top bots, crawled pages, by country, by user-agent, by day) and a **human business** report (visitors, sessions, funnel, orders/revenue from the order book, cities, sources, products, converted users).

## 2. Files changed

**API (`plantathome/api`)**
- `config/tracking.php` — NEW. Definitions, timeouts, retention, caps, the named-crawler table, generic/browser regexes, optional crawl key.
- `packages/marvel/src/Services/Tracking/BotDetectionService.php`, `TrafficType.php` — NEW classifier (pure, config-driven).
- `packages/marvel/database/migrations/2026_10_07_000100_add_traffic_classification_to_analytics.php` — NEW (see §3).
- `packages/marvel/src/Database/Models/AnalyticsEvent.php` — `TYPES`, `ALIASES`, `META_KEYS`, `FUNNEL_STEPS`, `KEY_EVENTS` updated, `canonicalType()`.
- `packages/marvel/src/Database/Models/AnalyticsSession.php` — NEW model.
- `packages/marvel/src/Http/Controllers/TrackingController.php` — rewritten ingest (§4).
- `packages/marvel/src/Rest/Routes.php` — `track` → named limiter, new `track/crawl`, new `command-center/traffic-report`.
- `app/Providers/RouteServiceProvider.php` — limiters `track` (1200/min by `CF-Connecting-IP`) and `track-crawl` (3000/min shared).
- `packages/marvel/src/Http/Middleware/LogRequests.php` — `api/track` skipped, path check before the settings read.
- `app/Console/Commands/PruneLogTablesCommand.php` — retention for `visitors`, `analytics_sessions`, `analytics_events` from config.
- `app/Console/Commands/EnrichIpLocationsCommand.php` — also sweeps recent `visitors.ip` lacking a country.
- `packages/marvel/src/Services/VisitorMetricsService.php` — the single source: `onlineCounts()`, `live()`, `funnel()`, `report()`, `visitorJourney()` (+ sessions).
- `packages/marvel/src/Services/MetricsService.php` — `live_visitors` ← humans online, `live_traffic` added, `liveVisitorsApprox()` + `VISITOR_WINDOW_MIN` deleted, `top_referrers` human-only, `applyRevenueFilter()` public static.
- `packages/marvel/src/Services/ActivityStreamService.php` — pulse `online` ← the service; severity map on canonical names.
- `packages/marvel/src/Services/CustomerMetricsService.php` — cart abandonment human-only on `order_created`/`payment_success`.
- `packages/marvel/src/Http/Controllers/CommandCenterController.php` — `liveVisitors(?type)`, `trafficReport(?days)`.
- Tests: `tests/Unit/Tracking/BotDetectionServiceTest.php`, `tests/Feature/Tracking/{TrackingTestCase,TrackingIngestTest,VisitorMetricsTest}.php`; `IntegrationRevealTest` asserts the skip list.

**Storefront (`plantathome-shop-v2`)**
- `src/lib/analytics/track.ts` — direct transport, localStorage session with rotation, heartbeat + visibility, UTM first-touch, shopping city, path-only URLs, secure cookie, new path rules.
- `src/lib/analytics/tracking-bridge.tsx` — `city_changed` from the `pah-location-changed` event.
- `src/lib/analytics/bot-ua.ts`, `proxy.ts` — NEW crawler leg.
- Events: `src/page-bodies/product.tsx` (product_view), `src/page-bodies/search.tsx` (search), `src/store/quick-cart/cart.context.tsx` (add/remove), `src/components/cart/cart-item.tsx` (explicit removals), `src/components/cart/cart-sidebar-view.tsx` (view_cart), `src/components/checkout/address-grid.tsx` + `src/framework/rest/user.ts` (add_address), `src/components/checkout/payment/payment-grid.tsx` (select_payment), `src/page-bodies/order-payment.tsx` (payment_initiated), `src/components/payment/razorpay/razorpay-payment-modal.tsx` (payment_success/failed, order_success), `src/framework/rest/order.ts` (order_created, order_success, checkout_failed), `app/error.tsx` (checkout_failed). Duplicate `add_to_cart` removed from `add-to-cart.tsx`; three hand-placed `city_changed` calls removed.
- `e2e/tracking.spec.ts` — NEW.

**Admin (`admin/rest`)**
- `src/data/command-center.ts`, `src/data/client/api-endpoints.ts` — typed hooks, `?type=`, `traffic-report`.
- `src/pages/live-visitors/index.tsx` — rebuilt; `src/pages/live-visitors/report.tsx` — NEW.
- `src/components/command-center/{visitor-radar,hub-tiles,ceo-view}.tsx`, `src/components/dashboard/executive.tsx` — humans online + bot hint.

## 3. Database changes (one migration, column-guarded, MySQL + sqlite)

- `visitors` += `traffic_type` (8, default `unknown`), `bot_name` (64), `bot_type` (24), `country_code` (2), `state` (80), `shopping_city` (120), `utm_source/medium/campaign/content/term` (120, first touch), `engaged` (bool). Index `(traffic_type, last_seen)`.
- `analytics_sessions` NEW: `session_id` (indexed, **not unique**), `visitor_id`, `user_id`, `traffic_type`, `bot_name`, `started_at`, `last_seen_at`, `landing_page`, `exit_page`, `referrer`, `utm_*`, `page_views`, `engaged`. Indexes `session_id`, `visitor_id`, `user_id`, `last_seen_at`, `(traffic_type, started_at)`. Device/browser/geo are joined from `visitors`.
- `analytics_events` += `traffic_type` (8). Index `(traffic_type, type, created_at)`.
- No backfill: a visitor is reclassified on its next ping; untouched rows age out (90 d).

## 4. API changes

`POST /api/track` (SDK; `throttle:track`) and `POST /api/track/crawl` (proxy; `throttle:track-crawl`). Both always return **204**.

Request (SDK):
```json
{ "visitor_id": "…", "session_id": "…", "user_id": 12, "page": "/plants", "referrer": "…",
  "shopping_city": "Gurugram", "utm": { "utm_source": "google" },
  "events": [ { "type": "page_view", "url": "/plants", "label": "…", "value": 1, "meta": { "product_id": 1 } } ] }
```
Heartbeat: same envelope with `"heartbeat": true, "events": []`. Crawl leg: `page, referrer, ip, country_code, state, city, source:"server", events` with the bot's UA as the `User-Agent` header (and `X-Track-Key` when `TRACKING_CRAWL_SECRET` is set).

Validation: body ≤ 16 KB; JSON parsed from `text/plain` or `application/json`; ≤ 20 events; `type` must be in `AnalyticsEvent::TYPES` after aliasing; URLs reduced to the path; `meta` restricted to `META_KEYS`, scalar, ≤ 255 chars each, encoded whole; ids `^[\w:.-]{8,64}$`; `user_id` ignored unless human.

`GET /api/command-center/live-visitors?type=all|human|bot|unknown` →
`{ window_sec, online:{total,human,bot,unknown}, human:{customers,guests,engaged}, by_device, visitors[] }` (rows carry `traffic_type, bot_name, bot_type, is_customer, engaged, shopping_city, user_agent (non-human only)`).
`GET /api/command-center/traffic-report?days=1|7|30` → `{ totals, by_day[], human{…}, top_pages, top_cities, sources, top_products, converted_users, bots{visitors, sessions, page_views, top_bots, top_pages, by_country, by_user_agent} }`.
`GET /api/command-center/funnel?days=` → human-only; `steps[]` plus flat keys; `conversion_pct` kept.
`command-center/overview.live_visitors` = humans online; `live_traffic` = full split.

## 5. Event taxonomy

`page_view, product_view, category_view, search, city_changed, location_detected, location_denied, add_to_cart, remove_from_cart, view_cart, begin_checkout, add_address, select_payment, serviceable_order, non_serviceable_order, payment_initiated, payment_success, payment_failed, checkout_failed, order_created, order_success`. Heartbeats are not events. Aliases accepted from old bundles: `checkout_start→begin_checkout`, `payment_start→payment_initiated`, `payment_complete→order_success`, `order_placed→order_created`.

## 6. Traffic classification

`BotDetectionService::classify(ua)`, in order: UA shorter than 10 chars → **unknown**; matches the named table in `config/tracking.php` → **bot** (name, type ∈ search/ai/seo/social/monitor/tool); matches the generic automation regex (bot, crawler, spider, curl, wget, python-requests, headless, …) → **bot** (type `other`); looks like a mainstream browser (`Mozilla/5.0` + Chrome/Safari/Firefox/Edge/Opera/Samsung…) and contains no `+http` self-link → **human**; otherwise → **unknown**. Classification runs on every ping; it labels, it never blocks.

## 7. Definitions

- **Online** = `visitors.last_seen ≥ now − 120 s` (heartbeat every 30 s while the tab is visible).
- **Session** = an `analytics_sessions` row; a new one starts after 30 min without a ping (SDK rotates `pah_sid`; the server also starts a new row under a stale id).
- **Engaged** = human session with ≥ 2 page views, or ≥ 30 s on site, or a product_view / add_to_cart / begin_checkout. Only flips to true.
- **Customer** = online human with `user_id`; **Guest** = online human without one.
- **Bot** = classified automated (§6). Never a customer, never in the funnel, never in business metrics.
- **Unknown** = could not be placed. **Total** = human + bot + unknown. **Business traffic** = human.
- A page view is counted from exactly one leg: humans by the SDK, bots by the crawl leg (Googlebot runs JS and would otherwise count twice).

## 8. Dashboards

- `/live-visitors`: KPI strips from `live-visitors` (online split; customers/guests/engaged); TODAY strip from `traffic-report?days=1`; All/Actual Users/Bots/Unknown tabs filter server-side; bot columns show name/type/UA; the radar has four states; the funnel is the human-only one; the dossier shows class, bot name, UA (non-human), sessions, engaged.
- `/live-visitors/report`: `traffic-report?days=7|30`.
- Executive / CEO view / hub tiles: `overview.live_visitors` (humans) with `live_traffic` bot/unknown counts in the hint. Live Activity's pulse `online` is the same number.

## 9. Bot analytics

Bots are stored with `traffic_type=bot`, `bot_name`, `bot_type`; one bot visitor = one (user-agent, ip). The report lists top bots (visitors, sessions, page views), top crawled pages, countries, user-agents, and sessions/page views per day; the live deck's Bots tab shows who is crawling right now and which page.

## 10. Tests

- `php artisan test tests/Unit/Tracking` — 30 passed (named crawlers, generic automation, eight browsers incl. a "Robotic X1" device name, six unknowns).
- `php artisan test tests/Feature/Tracking` — 26 passed / 169 assertions on the real migrations in sqlite: new/existing visitor, heartbeat, session rotation and 30-min continuation, JS bot stored but not double-counted, crawl leg counted with geo, human UA on the crawl leg not counted, bot never a customer, UTM first-touch vs per-session, URL sanitising, meta JSON validity, oversized/malformed/invalid payloads, type whitelist + aliases + cap, Cloudflare-header geo, `ip_locations` fallback, missing geo, shopping city + `XX` country, JSON content-type, crawl key; online split, inactivity drop, heartbeat keeps online, funnel ignores bots, report totals/by-day/bots/cities/sources/products/converted users/`days=1`, journey sessions.
- `tests/Feature/Integrations/{LogRequestsRedactionTest,IntegrationRevealTest}` — green (skip list asserted).
- Storefront `npx tsc --noEmit` + `next build` (Proxy compiled); admin `npx tsc --noEmit` + `yarn build`.
- Staging: `e2e/tracking.spec.ts` 3/3 (identity + clean path + utm; product_view with id; heartbeat at 30 s, none while hidden, no 5xx); `e2e/city-chip.spec.ts` 12/12; HTTP probes: SDK 204, heartbeat 204, crawl 204, 16 KB body 204, admin endpoints 403 unauthenticated, storefront 200 for bot and human UAs. Migration applied on MySQL locally with all indexes; `config:cache` loads `tracking.php`.
- Not run: `storefront-golden-path.spec.ts` fails on staging data unrelated to this work (dead Unsplash image in CMS content; Monstera "not available in Delhi yet").

## 11. Performance

Ingest is one visitor upsert, one session select+save and one bulk insert; a heartbeat is two UPDATEs. Beacons no longer transit the Node proxy on the storefront box and are no longer written to `request_logs`. Live counts use `visitors(traffic_type,last_seen)` and `analytics_sessions(last_seen_at)`; reports use `(traffic_type, started_at)`, `(traffic_type, type, created_at)` and cache 30 s (today) / 300 s (7/30 d) — cache is an optimisation only. No rollup table yet (`ponytail:` note in `config/tracking.php` gives the ceiling). Retention: events 30 d, sessions and visitors 90 d, in `logs:prune`.

## 12. Environment / config

No new env vars are required. Tunables are plain values in `config/tracking.php`. Optional: `TRACKING_CRAWL_SECRET` (API, read in that config file) + the same value as `TRACKING_CRAWL_SECRET` on the storefront server → the crawl leg then rejects pings without `X-Track-Key`. Storefront: `NEXT_PUBLIC_TRACKING_ENABLED=false` still disables the SDK. Owner step for city-level geo: Cloudflare → Rules → Settings → Managed Transforms → **Add visitor location headers** (free); without it country still works and city falls back to `ip_locations`.

## 13. Limitations

Clearing cookies, another browser/device or a private window = a new visitor. Ad blockers can drop SDK beacons (humans under-counted; bots unaffected). Crawlers are seen only when their UA matches the coarse proxy regex: a crawler with a browser-shaped UA and no self-link is *human* if it runs JS and *invisible* if it doesn't. UA spoofing is not verified (no reverse DNS). Geo is coarse (Cloudflare city at best, ipwho.is fallback; staging API has no Cloudflare headers). `unknown` exists because classification is conservative by design. History is bounded by retention. The Live Visitors API is still super-admin-only while the page gate is `reports.view` (pre-existing; staff without super-admin see an empty deck). The storefront repo ignores `.env*`, so there is no tracked `.env.example` there.
