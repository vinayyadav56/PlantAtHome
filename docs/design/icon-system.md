# PlantAtHome Icon System

**Status:** Live standard — applies to every repo (storefront `plantathome-shop-v2`, admin `admin/rest`, mobile `plantathome-app`).
**Last updated:** 2026-08-10

## The one rule

**Lucide is the single primary icon library.** No other icon library may be added — no react-icons, Heroicons, Font Awesome (npm *or* CDN), MUI icons, Ionicons, or hand-drawn "lucide-lookalike" SVGs for standard UI concepts. If a concept exists in Lucide, use Lucide.

| Repo | Package | Entry point |
|---|---|---|
| Storefront | `lucide-react` | `src/components/ui/icon.tsx` — import glyphs **only from this file**, never from `lucide-react` directly. It re-exports the approved set + an `<Icon>` wrapper. |
| Admin | `lucide-react@0.454.0` | Direct named imports from `lucide-react` (repo convention). Nav resolves through `src/components/icons/lucide-map.tsx`. |
| Mobile | `lucide-react-native` | `src/components/ui/Icon.tsx` — the `Icon`/`PlantIcon` funnel with the `GLYPH` alias map. All call sites go through it. |

Named imports only — never the dynamic `icons` catalog import (it defeats tree-shaking).

## Visual standard

- **Style:** Lucide outline.
- **Stroke:** `strokeWidth 2` (Lucide's default — do not pass an override).
- **Color:** `currentColor` — color comes from the parent's text color (Tailwind `text-*` class) or a `style={{ color }}` when a hex is design-mandated.
- **Default size:** 20px. Allowed scale: **16 / 18 / 20 / 24 / 32 / 40 / 48**, plus **12 / 14 for tiny inline text accents** (a glyph sitting inside a sentence or a compact chip).
- Pass size via the `size` prop. Use `h-*/w-*` classes only when responsive sizing (`sm:h-5` …) requires it.
- **Filled states** (active heart, earned star, solid play): the same outline glyph with `fill="currentColor"` (stars also `strokeWidth={0}`). Never a second "solid" icon set.

### Documented exceptions (the only two)

1. **Admin nav chrome = strokeWidth 1.8.** The dense sidebar/topbar surface pins 1.8 uniformly via `lucide-map.tsx`'s `makeIcon()` (plus its collapse chevrons and theme toggle). Content surfaces use 2.
2. **Charts are not icons.** SVG strokes inside data-viz (sparklines, progress rings, radar) follow the chart's own design, not this standard.

## Semantic table — one concept, one icon, everywhere

| Concept | Glyph | Concept | Glyph |
|---|---|---|---|
| cart / bag | `ShoppingBag` | success (inline) | `Check` |
| search | `Search` | success (badge) | `CircleCheck` |
| wishlist / favourite | `Heart` (+`fill` when active) | error (inline) | `X` |
| delivery / shipping | `Truck` | error (badge) | `CircleX` |
| express / local delivery | `Bike` | warning | `TriangleAlert` |
| parcel | `Package` (plain cube: `Box`) | info / note | `Info` / `CircleAlert` |
| location / pincode | `MapPin` | close / dismiss | `X` |
| user / account | `User` (staff: `UserRound`) | menu (hamburger) | `Menu` |
| phone | `Phone` | back / forward | `ChevronLeft` / `ChevronRight` |
| email | `Mail` | expand / collapse | `ChevronDown` / `ChevronUp` |
| stepper | `Plus` / `Minus` | directional CTA | `ArrowRight` / `ArrowLeft` |
| password visibility | `Eye` / `EyeOff` | security / privacy | `Lock` |
| rating | `Star` (+`fill` when set) | trust / guarantee | `ShieldCheck` |
| verified | `BadgeCheck` | plant / brand accent | `Leaf` |
| seedling / eco | `Sprout` | flower | `Flower2` |
| watering / care | `Droplet` | sunlight | `Sun` |
| gift | `Gift` | home | `Home` |
| date | `CalendarDays` | time | `Clock` |
| payment | `CreditCard` | wallet / earnings | `Wallet` |
| external link | `ExternalLink` | share | `Share2` |
| delete | `Trash2` | edit | `Pencil` |
| filter | `SlidersHorizontal` | categories grid | `LayoutGrid` |
| camera / photo | `Camera` | microphone / voice | `Mic` |
| notification | `Bell` | logout | `LogOut` |
| language / region | `Globe` | play video | `Play` (+`fill` when solid) |
| returns | `RotateCcw` | refresh / retry | `RefreshCw` |
| help / question | `CircleHelp` | AI / magic | `Sparkles` / `WandSparkles` |
| copy | `Copy` | link / webhook | `Link` |
| support | `Headset` | invoice / receipt | `Receipt` |
| upload / download | `Upload` / `Download` | like / dislike | `ThumbsUp` / `ThumbsDown` |

Adding a new concept = adding one export to the storefront's approved set (`ui/icon.tsx`) and one row here.

## Storefront `<Icon>` wrapper API

```tsx
import { Icon, Truck } from '@/components/ui/icon';

<Icon as={Truck} />                       // 20px, stroke 2, aria-hidden
<Icon as={Truck} size={16} />             // sized on the scale
<Icon as={Truck} label="Track order" />   // role="img" + aria-label (icon-only controls)
```

Direct glyph usage is equally valid (and the dominant pattern): `<Truck size={16} className="text-sage-300" aria-hidden />`.

## Accessibility

- **Decorative icons** (next to text that carries the meaning): `aria-hidden` — always.
- **Icon-only interactive elements**: the *element* gets `aria-label` ("Close cart", "Decrease quantity"); the icon inside stays `aria-hidden`.
- **Status icons paired with text** (✔ next to "We deliver to 122001"): `aria-hidden` — the text carries the meaning.
- Retained decorative illustrations must carry `aria-hidden` too.

## Fallback policy — dev-loud, prod-safe

Every name-keyed funnel warns in dev on an unmapped name and renders a platform-appropriate prod fallback:

| Funnel | Dev | Prod render |
|---|---|---|
| storefront `src/lib/get-icon.tsx`, `LineIcon`, `GsIcon` | `console.warn('[icons] unmapped…')` | `null` / `Leaf` (unchanged behaviour) |
| admin `src/utils/get-icon.tsx` | `console.warn` | red "X is not a valid icon" label (internal tool — loud is correct) |
| mobile `Icon.tsx` `resolve()` | `console.warn` (`__DEV__`) | `Leaf` |

## Retained custom icons (deliberately NOT Lucide)

| Set | Why retained |
|---|---|
| **Social brand marks** — storefront `src/components/icons/social/*` (Instagram, Facebook, YouTube, Twitter, Pinterest) | Lucide dropped brand icons entirely (absent from current releases). One custom brand set beats a mixed one. |
| **Payment brand marks** (footer VISA/Mastercard/UPI/RuPay chips, admin `icons/payment-gateways/`) | Brand assets, not UI concepts. |
| **LinkedIn logo** in `auth/login-form.tsx`, **"G"/"in" text marks** on mobile login | Brand marks. |
| **DB-driven picker palettes** — storefront `icons/category|groups`, admin `icons/category|type|summary|shop-single|flags|social|shop-transfer|store-notice-social` | Records store these icon names; both apps resolve them by DB value. Swapping breaks stored content. |
| **Illustrations / empty states** — empty-cart art, `no-data-found`, 404/not-found/empty-products botanicals, tracking-map art, footer ghost leaf, `pah` gilded ornaments | Decorative artwork, not icons. All `aria-hidden`. |
| **Animated paths** — storefront `ui/success.tsx`, `plant-loader` (both repos), admin `svg-loader` | CSS path-draw animations depend on bespoke path structure. |
| **No-equivalent bespoke glyphs** — pot-picker's pot/bare-roots pair, slashed-cart "out of stock" | No faithful Lucide equivalent; documented one-offs, `aria-hidden`. |

## Emoji policy

- **Emoji as a standalone UI indicator** (status ✓/✗/⚠️, button icons 📱/💬/📷, tile icons 🎁/🚚) — **banned**; use the semantic table.
- **Emoji inside sentence copy** ("You've unlocked FREE delivery! 🎉") — brand voice, allowed.
- **Emoji inside data strings** sent to the backend (order-note strings) — data, untouched.

## Do / Don't

- ✅ `import { Check } from '@/components/ui/icon'` (storefront) · `import { Check } from 'lucide-react'` (admin) · `<Icon name="checkmark" />` (mobile funnel)
- ✅ Reuse the semantic table; propose a table addition when a genuinely new concept appears.
- ❌ `import { icons } from 'lucide-react'` (dynamic catalog — kills tree-shaking)
- ❌ New inline `<svg>` for anything in the semantic table.
- ❌ New icon npm packages, icon fonts, or CDN icon stylesheets.
- ❌ Ad-hoc `strokeWidth` overrides outside the two documented exceptions.
