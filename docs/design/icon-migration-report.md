# PlantAtHome — Icon Migration Report

**Migration date:** 2026-08-09 → 2026-08-10.
**Standard applied:** [icon-system.md](icon-system.md). **Pre-migration state:** [icon-audit.md](icon-audit.md).

## Outcome in one line

Every UI icon across storefront, admin and mobile now renders from **Lucide** at strokeWidth 2 / currentColor on the documented size scale, through one funnel per repo — with brand marks, DB-driven palettes and genuine illustrations retained as documented customs.

## Storefront (`plantathome-shop-v2`) — 8 commits

| Batch | Commit | What changed |
|---|---|---|
| B1 foundation | `9c56783` | `lucide-react` installed (^1.31.0). New `src/components/ui/icon.tsx`: `<Icon>` wrapper (20px/stroke-2/aria defaults) + ~95 approved-glyph re-exports — the single import point. `get-icon.tsx` dev-warn on unmapped names. |
| B2 map internals | `b87858c` | The 4 centralized funnels re-implemented on Lucide with frozen APIs — `line-icons.tsx` (17 names), `storefront/icons.tsx` (25 `Icon.*` keys), `garden-service/icons.tsx` (12), `orders/tracking/icons.tsx` (17) — **~110 call sites untouched**. |
| B3 Font Awesome kill | `2802ac2` | All 23 `fa-*` usages replaced (Lucide + local brand set incl. new `PinterestIcon`, Simple Icons CC0). CDN preconnect + async stylesheet + noscript deleted from `app/layout.tsx` — one less third-party origin on every route. |
| B4 checkout emoji | `a5fc9f5` | ✓/✗/⚠️/📍/🚚/🛵/📦/🎁 rendered as status indicators → Check/X/TriangleAlert/MapPin/Truck/Bike/Package/Gift in pincode-serviceability, delivery-estimate, delivery-location-verification, delivery-notify-me, corporate-panel. |
| B5 chrome | `f8d78aa` | Bottom-nav (23px/1.7-2 toggle → 24px/stroke 2), footer (16 svgs → brand set + Lucide), header (3 raw svgs), product cards (hearts unified 16/21 → 18), counter stepper (12/14px mismatch → 16/16), cart sidebar (7 strokeWidths → 2). |
| B6 long-tail | `455cf37` | ~90 remaining ad-hoc svgs + page emoji across product details/cards, reviews, questions, home sections, search-view, profile, checkout steps, dashboard sidebar, city gate, toast close, corporate-gifting tiles. |
| B7 legacy flat files | `83c3bf8` | 53 flat `icons/*.tsx` common-UI components re-implemented as one-line Lucide wrappers with original export names — consumer files untouched. The 3-dot "more" triggers map to `Ellipsis`/`EllipsisVertical`. |

**Numbers:** 429 inline `<svg>` before → UI-icon svgs now 0 outside retained customs; 10 strokeWidths → 1 (+0 exceptions in this repo); Font Awesome CDN requests: 1/route → 0; icon systems: 6 → 1.

## Admin (`admin/rest`) — 3 commits

| Batch | Commit | What changed |
|---|---|---|
| A1 dead code | `e59a0ff` | Deleted `icons/sidebar/` (48-export barrel), `ui/sidebar-menu.tsx`, `icons/shops/` (5), 17 flat orphans (incl. `edit copy.tsx`, `upload-icon copy.tsx`) — 73 files. The 4 live topbar imports repointed to Lucide aliases (MessageCircle/Bell/ShoppingBag/FileText). |
| A2 normalization | `0abfa81` | strokeWidth 2 everywhere outside the documented nav-chrome 1.8 (now commented at `makeIcon()`); drifted 1.9s snapped to 1.8; timeline 3→2. Last hand-drawn glyphs → `CircleHelp`/`Link`/`GripVertical`. `get-icon` dev-warn added (red-label prod fallback kept — internal tool). |
| A3 text-glyph icons | `2bb1694` | Standalone ✓/✗/✕/⚠/🌿 across 18 files → Check/X/CircleCheck/CircleX/TriangleAlert/Plus/Leaf; icon-only buttons gained `aria-label`s. |

## Mobile (`plantathome-app`) — 1 commit (`340bcd5`)

- The 9 silently-Leaf names now resolve (`wallet`→Wallet, `call`→Phone, `log-out`→LogOut, `map`→Map, `add-circle`→CirclePlus, `chatbubble-ellipses`→MessageCircle, `document-text`→FileText, `ellipse`→Circle, `phone-portrait`→Smartphone) — the DP/nursery Earnings tab and profile Sign-out row show real icons.
- `resolve()` warns in `__DEV__` before the Leaf fallback.
- strokeWidth unified at 2 (was 1.9/1.6); `cart`→`ShoppingBag` matching web.
- Emoji icons replaced: login 📱→Smartphone, 💬→MessageCircle; care 📷→Camera icon + text; service-areas 🛵/📦→Bike/Package.
- **Not deployed this pass** — ships with the next EAS build (`npx expo run:ios|android`; Expo Go can't run it).

## Retained customs registry (deliberate non-migrations)

- **Brand marks:** storefront `icons/social/*` (now the canonical social set: Instagram/Facebook/YouTube/Twitter + new Pinterest), `google.tsx`, `whatsapp.tsx`, LinkedIn logo in login-form, footer payment chips + Mastercard circles, mobile "G"/"in" text marks. Lucide dropped brand icons — one custom brand set beats a mix.
- **DB-driven picker palettes:** storefront `icons/category|groups|payment-gateways`; admin `icons/category|type|summary|shop-single|flags|social|shop-transfer|store-notice-social`. Records store these names; both apps resolve them by DB value.
- **Illustrations / animated paths:** empty-cart art, 404/not-found/empty-products botanicals, tracking-map art, footer ghost-leaf watermark, `pah` gilded ornaments, `ui/success.tsx` + `plant-loader` CSS path animations, admin `no-data-found` + `svg-loader`. All `aria-hidden`.
- **No-Lucide-equivalent bespoke glyphs:** pot-picker's pot/bare-roots pair, slashed-cart "out of stock", care-guide humidity/paw pair, why-plantathome "Hand Picked", storefront `timer-separator`, `sad-face`.
- **Copy/data emoji:** sentence emoji ("…FREE delivery! 🎉"), backend note strings in `place-order-action.tsx`, in-sentence ✓ in admin location-capture and add-from-catalog.

## Dead code — documented, not deleted

- Storefront `layouts/classic.tsx` + seven `pa-*.tsx` (emoji-heavy) are unreachable from every live layout value, but a DB `layout` value could in principle select them — left in place, excluded from migration. Candidates for deletion with product sign-off.
- `public/brand/glyph-*.png` ×6 + `mark-house.png`: unreferenced assets.
- `admin/graphql` workspace: pre-migration snapshot (full legacy icon set, zero Lucide), not built for prod — recommend deleting the workspace (also flagged in the 2026-08-09 hardening pass).

## Verification

- Per-batch `tsc --noEmit` green in all three repos (admin's 3 pre-existing `product-form.tsx` react-hook-form type errors exist on clean HEAD, unrelated).
- Grep gates: zero `fa-solid|fa-brands|cdnjs.*font-awesome`; zero standalone status emoji outside copy/data/dead files; zero non-standard `strokeWidth` outside the two documented exceptions.
- Storefront production build + Playwright staging pass (home mobile+desktop, PDP, cart, checkout, tracking, footer) with a zero-console-error gate before prod promotion.
- Rollback: one commit per batch → `git revert <sha>`. No schema/data risk — every DB-driven icon name keeps resolving because the funnels and palette folders were preserved.
