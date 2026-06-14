# PlantAtHome — Plant Doctor + Multilingual: PENDING tracker

Status legend: ⏳ you provide / run · 🤖 I run when unblocked · ✅ done.

**Already DONE + deployed to STAGING** (code-complete, build-verified, CI green):
- Plant Doctor accuracy rebuild (botanical gate + Claude Opus 4.8 structured `is_plant` gate, no false
  diagnoses) — AI monorepo `main` + pah-api `staging` (migration ran).
- 23 Indian locales enabled in shop + admin code; all AI services honor `language`.
- Translation engines built: shop `npm run i18n:gen`, `php artisan marvel:translate-lang`,
  `php artisan marvel:translate-catalog`; mobile-app i18n foundation.

---

## P1 ⏳ Set Vercel env vars (activates the multilingual UI) — shop + admin
The 23-locale UI is env-gated; the deployed code falls back to English until these are set.
On **both** Vercel projects (shop `vinayyadav56/shop`, admin `vinayyadav56/PlantAtHomeAdmin`), Settings →
Environment Variables (Preview/Staging scope), add/update:
```
NEXT_PUBLIC_ENABLE_MULTI_LANG = true
NEXT_PUBLIC_DEFAULT_LANGUAGE  = en
NEXT_PUBLIC_AVAILABLE_LANGUAGES = en,hi,bn,ta,te,mr,gu,kn,ml,pa,or,as,ur,sa,ne,kok,mai,doi,ks,sd,sat,mni,brx
```
Then redeploy (or it applies on the next push). Repeat for the Production scope when promoting.

## P2 ⏳ Set PLANT_ID_API_KEY on the plant-doctor Railway service
Enables the independent botanical detection gate (Claude's own `is_plant` gate still protects without it).
Railway → plant-doctor service → Variables → `PLANT_ID_API_KEY = <key from plant.id>`. Redeploy.
(Optional tuning vars: `PLANT_DOCTOR_MODEL`, `PLANT_ID_REJECT_THRESHOLD`, `PLANT_DOCTOR_MAX_TOKENS`.)

## P3 🤖/⏳ Run the UI-string translation (B2) — needs ANTHROPIC_API_KEY + budget
Generates `public/locales/<lang>/*.json` for the 22 new languages (English already present).
```bash
# shop
cd shop && ANTHROPIC_API_KEY=sk-ant-... npm run i18n:gen
# admin (reuse the same script)
cd pah-admin/rest && ANTHROPIC_API_KEY=sk-ant-... \
  node ../../shop/scripts/translate-locales.mjs --src public/locales/en --out public/locales
```
Idempotent (skips existing; add `--force` to redo). Commit the generated `public/locales/*` folders.
Then have native speakers review before production.

## P4 🤖/⏳ Run the email/SMS lang-file translation (B3) — needs ANTHROPIC_API_KEY
```bash
# on the API host / locally with DB-less file access
php artisan marvel:translate-lang --langs=hi,bn,ta,te,mr,gu,kn,ml,pa,or,as,ur,sa,ne,kok,mai,doi,ks,sd,sat,mni,brx
```
Writes `resources/lang/<lang>/*.php`. Commit them. Confirm `TRANSLATION_ENABLED=true` on the API env.

## P5 🤖/⏳ Run the catalog content translation (B4) — STAGING FIRST, validate one product
The riskiest step (creates per-language product/category rows). Validate before any bulk run:
```bash
# 1. one product, one language, dry run (no writes)
php artisan marvel:translate-catalog --entity=product --product=<ID> --langs=hi
# 2. commit it
php artisan marvel:translate-catalog --entity=product --product=<ID> --langs=hi --commit
# 3. VERIFY the storefront renders /products/<slug>?language=hi (name/description localized,
#    image, price, categories all intact). THEN scale gradually:
php artisan marvel:translate-catalog --entity=both --langs=hi,bn,ta --commit --limit=50
```
Notes: translations share the product `slug`, set `language`, null the (uniquely-indexed) `sku`, and copy
category/tag pivots. VARIABLE products' variation rows are NOT duplicated — review those separately. Run on
**staging** first; only run on prod after verifying.

## P6 🤖 App string extraction + locale generation (B6 follow-up)
Foundation is live (device-locale, RTL, LanguagePicker in Profile). Remaining: extract every screen's
strings into `src/i18n/locales/en.json`, adopt `t()` across screens, generate the 22 locale JSONs (reuse the
engine), and register them in `src/i18n/index.ts`. Mechanical; do per screen.

## P7 🤖 Wire locale into the recommendation/semantic-search shop callers (small B5 follow-up)
Server already honors `language`; chat/voice/product-search callers already pass it. Add `language: locale`
to the recommendation + semantic-search shop hooks for full coverage.

## P8 ⏳ VERIFY on staging (before promoting to prod)
- Plant Doctor: upload a **non-plant** (dog/wall/person) → **rejected**, no diagnosis; a real diseased leaf →
  correct species + accurate severity-graded diagnosis; request in Hindi/Tamil → response fully localized;
  admin Plant Doctor stats show rejection rate + Claude-priced cost.
- Multilingual: switch shop/admin language → UI localized (after P3); product names/descriptions localized
  (after P5); AI chat/recommendations/search reply in the chosen language.

## P9 🤖/⏳ Promote staging → production (after P8 passes)
Per repo: AI monorepo is already on `main` (Railway); pah-api `gh pr create --base main --head staging` +
merge + `production.yml`; shop/admin promote on Vercel (set the P1 env vars in the Production scope);
app via EAS. Set P2 (PLANT_ID_API_KEY) on the prod plant-doctor service too.

---
Generated alongside the Plant Doctor + multilingual initiative. See the plan at
`~/.claude/plans/okay-start-working-on-zesty-shamir.md`.
