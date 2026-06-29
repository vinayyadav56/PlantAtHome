# Mobile App — i18n Integration Contract

The React Native / Expo app is **not in this repo**, so this is the contract for
wiring it to the same multilingual backend with no duplicate translation work.
See `MULTILINGUAL_ARCHITECTURE.md` for the engine.

## Principle
The app reuses **exactly** the web stack: the same API (dynamic content overlay),
the same UI locale packs, and the same provider/cache — so a string is never
translated twice across web + mobile.

## 1. Dynamic content (products, categories, …)
Send the user's language on every API call — either is honoured (query param wins):
```
GET /api/products?language=hi
# or
Accept-Language: hi
```
The API returns the localized `name`/`description` (English fallback on a miss).
No app-side translation logic; identical responses to web.

## 2. UI strings (offline-capable)
- Use `i18next` + `expo-localization`; **reuse the same locale JSON packs** as the
  web (`shop/public/locales/<lang>/common.json`). Ship them in the bundle and/or
  fetch + cache so the UI is localized **offline**.
- Recommended: a tiny `/api/i18n/<lang>` (or CDN) endpoint that serves the pack
  for over-the-air updates without an app release; cache with an ETag/version.

## 3. Language detection priority
1. user's saved preference (server, on login)
2. persisted device choice (`AsyncStorage`, e.g. `pa_lang`)
3. device locale (`expo-localization`)
4. default (`en`)
Persist the choice and set `I18nManager` RTL for `ur/ks/sd`.

## 4. Push notifications + email
Localize at send time using the recipient's stored language:
- Notifications/Mailables call `app()->setLocale($user->language)` and read
  `resources/lang/<lang>/*.php` (generate with `marvel:translate-lang`).
- Dynamic fields (e.g. product names in an order email) come pre-localized from
  the overlay when the entity is read in the user's language.

## 5. Deep links
Carry the language through deep links (`?language=hi` or an app-level locale) so a
link opens in the recipient's language.

## Server-side prerequisites (already shipped)
- `Accept-Language` middleware honoured on every API route ✅
- Overlay returns localized dynamic content ✅
- Provider + queue + cache shared with web ✅
- Per-user `language` preference column — add to the users table if not present,
  set on login, and pass to notifications/emails.

## Effort when the app lands
Thin: add `i18next` + the shared packs, a language picker, send `Accept-Language`,
and a user-preference sync. No new backend.
