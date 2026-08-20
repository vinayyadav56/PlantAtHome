# PlantAtHome — Icon Consistency Audit (pre-migration findings)

**Audit date:** 2026-08-09 · Three exhaustive per-repo audits (storefront, admin, mobile + cross-repo deps).
**Companion docs:** [icon-system.md](icon-system.md) (the standard), [icon-migration-report.md](icon-migration-report.md) (what changed).

## Headline

The working assumption ("web is on Lucide, mobile is on Ionicons/Material") was **inverted**:

- **Mobile** was already 100 % Lucide through one funnel file.
- **Admin** was already majority-Lucide (nav fully migrated).
- **The storefront had zero Lucide** — no icon npm package at all. Six competing hand-rolled icon systems, an externally-loaded Font Awesome CDN font, and emoji as functional checkout status icons.

## Storefront (`plantathome-shop-v2`) — the bulk of the problem

**Icon libraries installed:** none. `lucide-react`, `react-icons`, `@heroicons/react`, `@fortawesome/*`, `@mui/icons-material`, `bootstrap-icons`: all absent from `package.json` and source (0 grep hits each).

### Six competing hand-rolled icon systems

| # | System | Size | Call sites |
|---|---|---|---|
| 1 | `src/components/icons/` legacy folder (Pickbazar-derived) | 182 files / 180 components (incl. `category/` 75 — DB-driven, `groups/` 15, `payment-gateways/` 11, `social/` 5, `shop/` 5) | 95 files, 168 import statements |
| 2 | `src/components/icons/line-icons.tsx` — `<LineIcon name=…>` 18-key PATHS map, default strokeWidth 1.9 | 1 file | 57 sites / 14 files |
| 3 | `src/components/storefront/icons.tsx` — `Icon.*` object, 25 glyphs, strokeWidth 1.8 | 1 file | 32 sites / 3 files |
| 4 | `src/components/garden-service/icons.tsx` — 12-key local map (self-admits duplicating #2 in its own comment) | 1 file | garden-service page |
| 5 | `src/components/orders/tracking/icons.tsx` — 17 exports, strokeWidth 1.8 ("kept local … regardless of the mixed icon sources elsewhere" — its own comment) | 1 file | tracking page |
| 6 | `pah/bottom-nav.tsx` local ICONS map — 23×23px, strokeWidth toggling 1.7/2 per tab state | inline | site-wide mobile nav |

Same concepts drawn independently in multiple systems: leaf, truck, shield, menu, play, droplet, plus, lock, mapPin. Files 3–5 acknowledge the fragmentation in their own header comments.

### Font Awesome via CDN

`app/layout.tsx:81-97` injected `cdnjs.cloudflare.com/.../font-awesome/6.5.2/css/all.min.css` (async) on **every route** for 23 `<i class="fa-…">` usages / 19 distinct icons — all inside the mobile-home `pah/*` sections. Four were brand icons (instagram/pinterest/facebook-f/youtube).

### Ad-hoc inline SVG

429 `<svg>` occurrences across 259 files; **239 fully ad-hoc** outside the icons folder. Worst files: footer.tsx (16 — redrew socials from scratch while `icons/social/*` existed), cart-sidebar-view.tsx (7 icons at **7 different strokeWidths** 1.5–2.5 and 7 sizes), header.tsx (mixed `Icon.*` + `SearchIcon` + 3 raw svgs in one file), three independent product-card icon sets (16px vs 21px hearts), counter.tsx (a **mismatched stepper pair**: minus 12px / plus 14–18px).

### Emoji as functional UI

24 files / 66 occurrences. Live checkout: `pincode-serviceability.tsx` (✓/✗), `delivery-estimate.tsx` (🚚/🛵/📦), `delivery-location-verification.tsx` (✓/⚠️/📍), `delivery-notify-me.tsx` (✓), `offers/corporate-panel.tsx` (🎁/✓), `page-bodies/corporate-gifting.tsx` (🎉🧑‍💼🤝🌱🎨🚚✅🔒 as tile icons). `pa-shop-by-category.tsx` even ran a keyword→emoji mapping function (succulent→🌵, flower→🌸 …) — on the unreachable Classic layout.

### Stroke / size chaos

- **10 distinct strokeWidths** in active icon use: 1.5×27, 1.55×2, 1.6×32, 1.7×27, 1.8×62, 1.9×2, 2×131, 2.2×24, 2.3×3, 2.4×19, 2.5×21, 2.6×5.
- Arbitrary pixel sizes beside the standard scale: 31× `h-[18px]`, 8× `h-[21px]`, plus 7/10/13/15/17/20/22px one-offs (standard `h-4`×83, `h-5`×48 also in use).
- Non-24 viewBoxes compounding optical drift: `search-icon` 17.048×18, `cart-outlined` 17.6×19.6, `close-icon` 20×20.
- Heroicons path data hand-copied into `eye-icon.tsx` / `eye-off-icon.tsx` / `close-icon.tsx` without the package.

### Fallback

`src/lib/get-icon.tsx` returned `null` for unmapped names — silently invisible icons.

### Dead code (documented, not migrated)

`layouts/classic.tsx` + seven emoji-heavy `pa-*.tsx` files are unreachable (`app-shell/home-screen.tsx` maps every live layout value elsewhere). `public/brand/glyph-*.png` ×6 unreferenced.

## Admin (`admin/rest`)

**Already majority-Lucide:** `lucide-react@0.454.0`, 85 files importing 129 distinct icons directly. The whole sidebar/nav was previously migrated via `src/components/icons/lucide-map.tsx` (123 keys, `makeIcon()` pinning strokeWidth 1.8); all 117 nav icon-name strings resolved — zero red-error fallbacks.

Remaining findings:

- **288 legacy custom-SVG files** under `src/components/icons/`. Dead: `icons/sidebar/` (48-export barrel superseded by lucide-map; only 4 files still imported by topbar components) + orphaned `ui/sidebar-menu.tsx`; `icons/shops/` (5 files, zero references); **17 flat orphans** incl. `edit copy.tsx` and `upload-icon copy.tsx` (literal spaces in filenames). Live remainder = DB-driven picker palettes (category 75, type 15, payment 11, summary 11, shop-single 7, flags 6, social sets) — retained by design.
- **strokeWidth spread 0.8–3** at Lucide call sites: nav 1.8, order-timeline 3, shop layout 1.9, collapse chevrons 1.9, sparkline 1.5 (chart), most default 2.
- **Emoji/text glyphs as UI**: 36 files / 54 occurrences — `shipment-courier-panel.tsx` step-done '✓', settlements ✓/✗ status lines, `product-form.tsx` 🌿 buttons, assorted ✕ close/remove buttons.
- Hand-rolled one-offs: help "?" glyph (`module-help-button.tsx`), a react-icons-style 1024-viewBox link path (`copy-content.tsx`), a drag-handle dots svg (`homepage-categories`).
- `src/utils/get-icon.tsx` fallback = visible red error text (no dev warning).
- `admin/graphql` workspace: dead code, never received the Lucide migration (0 lucide imports, full 286-file legacy set) — untouched, recommend deletion.

## Mobile (`plantathome-app`)

**Already 100 % Lucide** (`lucide-react-native@1.28.0`) through `src/components/ui/Icon.tsx` — a single funnel with an ~85-glyph barrel and a `GLYPH` alias map keeping the old Ionicons/Material vocabulary. 140 call sites / 45 files, zero direct icon imports outside the funnel.

Remaining findings:

- **9 names silently rendered the Leaf fallback** (no dev warning): `wallet`, `wallet-outline`, `call-outline`, `log-out-outline`, `map-outline`, `add-circle-outline`, `chatbubble-ellipses-outline`, `document-text-outline`, `ellipse-outline` — e.g. the DP/nursery **Earnings tab icon was a leaf**, and the profile's "Sign out" row showed a leaf.
- strokeWidth 1.9 (outline) / 1.6 (filled) vs the standard 2; `cart` mapped to `ShoppingCart` while web standardized on `ShoppingBag`.
- Emoji as icons: login 📱/💬 buttons, care screen "📷 Take a photo", nursery service-areas 🛵/📦 mode chips. ("G"/"in" text marks are brand, retained.)

## Cross-repo

- No dead icon dependencies to remove anywhere; `@expo/vector-icons` present only transitively via Expo, unused.
- Shipping-service (Go) and Python services: no UI icons.
