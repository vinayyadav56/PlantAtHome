# Typography system (shop-v2)

_Updated 2026-08-11 alongside the checkout/typography fix (shop e360a86)._

## The stack

One switchable body font, defined once:

```css
/* plantathome-overrides.css */
--font-sans:    'Inter', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto,
                'Helvetica Neue', Arial, sans-serif;
--font-heading: var(--font-sans);   /* display serif ONLY via opt-in classes */
--font-body:    var(--font-sans);
```

- **Inter loads as a real document stylesheet** in `app/layout.tsx` (`id="pah-font-inter"`), so
  the FIRST paint is Inter — the typo-prepaint script only overrides when the admin picks a
  different font (Settings → Design System / Typography). Never re-add per-page font `<link>`s.
- Headings h1–h6 use the **body font sitewide** (weight + color carry hierarchy — owner pattern
  2026-07-27), via a plain base rule in `main.css`. The display serif is OPT-IN:
  `.font-display-serif` / `font-heading` (product names, brand moments). The old
  `h1-h5 { font-family: … !important }` override is gone — it excluded h6 and blocked the
  utility classes.

## Tokens — use these, not raw px

| Token | Use |
|---|---|
| `--fs-hero` (clamp 40→64) | hero headline |
| `--fs-section` (32→48) | page/section heading |
| `--fs-subhead` (24→32) | sub-section heading |
| `--fs-card` (24) | card titles |
| `--fs-body-lg` (18) / `--fs-body` (16) | body |
| `--fs-sm` (14) | secondary / labels |
| `--fs-caption` (12) | captions, metadata |
| Tailwind `text-h1..h6` | heading rem scale (main.css `--h1..--h6`) |

Weights: hero/page heading **700**, section heading **600**, labels/buttons **500–600**,
body **400**, price **700**, metadata **400**. Not everything bold.

## Rules

1. **No new `text-[NNpx]`** — pick the nearest token. No inline `style={{fontSize/fontWeight}}`.
2. No `font-family` declarations in components — inheritance from `body` + the two axis classes.
3. Checkout/cart/PDP/commerce chrome follow the tokens (normalized 2026-08-11).
4. Known debt: ~550 arbitrary `text-[Npx]` remain on MARKETING pages (plant-doctor,
   garden-service, home sections…) — internally consistent, deliberately untouched; convert
   opportunistically when a page is next edited.
5. Admin/vendor apps pin Inter at runtime (`_app.tsx`) — unchanged.
