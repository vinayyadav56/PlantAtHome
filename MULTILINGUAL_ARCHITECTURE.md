# PlantAtHome — Enterprise Multilingual Architecture

Production multilingual across the storefront, admin and API. Two cleanly
separated layers, each with its own caching + cost model.

| Layer | What | How | Cost |
|---|---|---|---|
| **UI translation** | buttons, labels, marketing copy | key-based i18n JSON packs (`next-i18next`), shipped per language | one-time per string, in the repo |
| **Dynamic content** | products, categories, … | **cache-table OVERLAY**, AI-translated on demand, cached forever | translate once, reuse forever |

**Initial languages:** `en` (default), `hi`, `mr`, `kn`, `ta`, `te`. Adding a
language requires **no code change** — see "Adding a language".

---

## Dynamic content — the cache-table overlay (no row duplication)

The canonical English row is the **only** row for a product/category. Its
translated fields live in a polymorphic `translations_cache` table and are merged
onto the entity **at read time**. There is never a duplicate product.

```
products (canonical, English only)        translations_cache
  id=100 name='Areca Palm'        ◄─────  translatable_type=…\Product
                                          translatable_id=100  language=hi
  GET /products?language=hi                translated_fields={"name":"एरेका पाम", …}
   → returns row 100 with name/desc        source_hash, status, version, is_reviewed
     overlaid from the cache (or            status: pending|translated|outdated|failed
     English on a miss — never blank)
```

### The read-overlay hook
The overlay lives at the **Eloquent model-attribute layer** — the single code
path shared by REST API Resources, GraphQL/Lighthouse field resolution, and
nested relations (`type`, `shop`, `category`). One trait localizes every read
surface at once.

- `Marvel\Translation\HasTranslationOverlay` — `use` it on a model + declare
  `protected array $translatable = ['name', 'description']`. It overrides
  `getAttributeValue()` and registers each retrieved row with the context.
  Currently on `Product` (`name`,`description`) and `Category` (`name`,`details`).
- `Marvel\Translation\TranslationContext` (request-scoped singleton) — bulk-loads
  all pending ids of a type in **one** query per `(type, language)` (Redis MGET →
  DB fallback), so a 100-row page never N+1s. A miss returns the canonical English
  value **and** (lazily) enqueues a translation job. Only `status='translated'`
  rows are served — `outdated` rows fall back to English ("never serve stale").
- Reads select the **canonical English row** (controllers/repos use
  `where('language', DEFAULT_LANGUAGE)`); the requested language drives the overlay,
  not the WHERE. Slugs stay English-derived (stable URLs).

### Provider abstraction (swap from admin, no deploy)
`Marvel\Translation\Contracts\TranslationProvider` →
`Providers/{Google(default),OpenAi,Claude,Azure,DeepL}`. `TranslationManager`
resolves the **active** provider from `translation_provider_configs` (api keys
`encrypted:array` at rest, masked on read) with an env fallback.
`TranslationService` dedupes identical strings via `translation_string_cache`
(translate each phrase once per language) and batches provider calls.

### Queue, versioning, cache
- `Jobs/TranslateEntityJob` — per (entity, language); dedicated `translations`
  queue, retry+backoff, `WithoutOverlapping`; on success writes the cache row +
  write-through Redis + busts the per-language response cache.
- `Observers/TranslatableObserver` — when an entity's English translatable fields
  change, marks its translations `outdated`, busts Redis, and requeues.
- **Redis** — `txn:{type}:{id}:{lang}` holds the assembled translated fields
  (`Cache::forever`, invalidated explicitly). Hit/miss counters feed the admin.

### Request language
`Http/Middleware/ResolveLanguage` (registered first on REST + GraphQL): honours
`?language=` (existing contract) → q-weighted `Accept-Language` → default. Merges
`language` into the request and primes the `TranslationContext`. Fully
backward-compatible: default English behaviour is byte-identical (the overlay is
inactive for the default language and gated by `config('translation.enabled')`).

---

## Admin (Language Management)
Super-admin page **Configuration → Languages & Localization** (admin/rest
`pages/languages`): coverage % per type×language, queue depth, pending/outdated/
failed, cache hit ratio, estimated cost; provider management (set active + save
encrypted key); per-row + bulk re-translate; clear cache. Backed by the super-
admin API (`translations/stats|missing|retranslate|bulk-retranslate|clear-cache|
mark-reviewed`, `translation-providers`).

---

## Runbook

### Adding a language (no code change)
1. Add the code to `TRANSLATION_LANGUAGES` (API `config/translation.php` / env) and
   to the shop's `NEXT_PUBLIC_AVAILABLE_LANGUAGES` (the CI workflow build env).
2. Generate the UI locale pack: `shop/public/locales/<lang>/*.json` (see the
   `translate-locales.mjs` generator) — or copy English to start.
3. Warm dynamic content: `php artisan marvel:translate-entities --langs=<lang>`.

### Going live with translated CONTENT
1. Configure a **server** Google key (Translation API enabled, *not* an HTTP-
   referrer-restricted browser key) — the engine reuses `GOOGLE_MAPS_SERVER_KEY`
   if no dedicated `GOOGLE_TRANSLATE_API_KEY` is set, or set the active provider +
   key in admin.
2. Warm: `php artisan marvel:translate-entities --type=both --langs=hi,ta` (run
   before enabling a language to avoid a cold-cache stampede).
3. Watch coverage climb on the admin dashboard.

### Adding a translatable content type
`use HasTranslationOverlay; protected array $translatable = ['…'];` on the model,
register it in `TranslateEntityJob::CACHE_GROUPS` + `TranslationAdminController::ENTITIES`.
Zero schema change (the cache table is polymorphic).

---

## Cost model
- Per-string dedupe + per-entity content-hash gate redundant calls.
- Google Translate ≈ $20 / 1M chars (cheapest at catalog scale); LLM providers
  are higher quality but pricier (see `cost_per_million_chars`).
- Estimated pending cost is surfaced in the admin before you commit a bulk run.

## Known limitations / follow-ups
- **Search** is English-only (SQL `LIKE` on `products`); translated-term search
  needs a join into `translations_cache` (deferred — value depends on content
  being translated first).
- **Order/cart snapshots** copy product names at purchase time and are
  intentionally *not* overlaid (record integrity).
- **GraphQL** arg-based `language` drives the overlay only via the
  `Accept-Language` header (the storefront uses REST, fully covered).
- The **Railway API runs the `sync` queue driver**, so lazy translation is
  disabled there (the overlay skips it to stay fail-safe); populate via
  `marvel:translate-entities`. A real async worker re-enables lazy on-demand.
- **mr/kn/te** UI packs + the catalog still need generating with a live key.

## Tests
`tests/Feature/TranslationEngineTest.php` — middleware precedence + Accept-Language
parsing, Google provider request/response (`Http::fake`), manager resolution,
context activation, and the sync-queue/missing-key fail-safe.
