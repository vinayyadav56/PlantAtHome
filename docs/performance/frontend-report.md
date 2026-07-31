# Frontend Report

> Generated 2026-07-31T07:43:25.765239+00:00
> Lighthouse 12.8.2 against **staging** (`plantathome-shop-staging.vercel.app`)
> Single page loads, not load tests — safe under Vercel's acceptable use policy.

## Core Web Vitals

| Page | Perf | FCP | LCP | TBT | CLS | Speed Index | TTI | TTFB |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| home-desktop | 67 | 1.72s | 2.72s | 144ms | 0.001 | 7.78s | 12.01s | 40ms |
| home-mobile | 33 | 3.72s | 7.61s | 1.24s | 0.080 | 36.41s | 66.20s | 39ms |
| search-desktop | 49 | 1.37s | 4.04s | 99ms | 0.595 | 1.93s | 4.05s | 37ms |
| search-mobile | 36 | 6.61s | 21.81s | 1.10s | 0.000 | 6.85s | 21.81s | 37ms |

## Verdict against Google's thresholds

| Metric | home-mobile | search-mobile |
|---|---|---|
| LCP | POOR (7.61s) | POOR (21.81s) |
| TBT | POOR (1.24s) | POOR (1.10s) |
| CLS | good (0.080) | good (0.000) |
| TTI | POOR (66.20s) | POOR (21.81s) |

**The server is not the problem.** TTFB is 37-40ms on every page. Everything above is
payload, JavaScript and images — work that happens after the bytes arrive.

`search-desktop` has **CLS 0.595**, roughly six times
the 0.1 "good" threshold. That is content jumping under the user's cursor as the
listing hydrates, and it is the single most user-visible defect here.

## Top opportunities (mobile home)

- **0.92s** — Properly size images
- **0.58s** — Serve images in next-gen formats
- **0.55s** — Reduce unused CSS
- **0.55s** — Reduce unused JavaScript
- **0.32s** — Eliminate render-blocking resources
- **0.18s** — Avoid serving legacy JavaScript to modern browsers
- **0.04s** — Initial server response time was short

## What these map to in the code

- **Properly size images / next-gen formats** — 48 raw `<img>` tags across 39 files serve
  unresized S3 originals, bypassing `next/image` entirely. Concentrated in exactly the
  above-the-fold places: the mobile product card, the homepage category and collection
  rows, and the PDP gallery.
- **Render-blocking resources** — Google Fonts and cdnjs Font Awesome are loaded as
  blocking `<link rel=stylesheet>` in `<head>`, so two third-party origins gate first
  paint on every route.
- **Unused JavaScript** — Stripe is bundled although Razorpay is the live gateway, and
  both `dayjs` and `date-fns` ship.
- **TTI on mobile** is extreme on both pages. `/[searchType]/search` is `force-dynamic`,
  so it is a full SSR render per request with no cache layer in front.

## Caveat worth stating

`search-mobile` LCP of 21.81s may be inflated by the city-selection gate, which blocks
the page until a city is chosen and is not representative of a returning visitor with a
stored city. Re-measure with `pah_customer_city` seeded before treating that specific
number as the steady-state figure. The desktop CLS finding is not affected by this.
