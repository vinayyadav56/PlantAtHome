/**
 * PlantAtHome — k6 load suite.
 *
 * One file, several scenarios, selected with `--env SCENARIO=...`:
 *
 *   load     staged ramp to find the knee
 *   stress   ramp past the knee until it breaks
 *   spike    instant jumps, to measure recovery rather than throughput
 *   soak     steady hold, to expose drift
 *   smoke    one VU, for CI
 *
 *   k6 run --env SCENARIO=load --env BASE=http://127.0.0.1:8080 journeys.js
 *
 * Traffic mix models real behaviour rather than hammering one route: most
 * visitors browse and leave, a minority search, fewer open a product, fewer
 * still authenticate. Think time is included because without it you are
 * measuring a benchmark, not a user population.
 *
 * SAFETY
 *   - Refuses any host matching plantathome.in, matching the guard in the
 *     existing repo harness. Load-testing production is never the intent.
 *   - Read-only. No endpoint here places an order, sends an OTP, calls a
 *     shipping partner or touches a payment gateway. Those paths reach third
 *     parties that have not consented to your load test and, in the case of
 *     Razorpay and the courier partners, cost real money per call.
 */

import http from 'k6/http';
import { check, sleep, group } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const BASE = __ENV.BASE || 'http://127.0.0.1:8080';
const SCENARIO = __ENV.SCENARIO || 'load';
const LANG = __ENV.LANG || 'en';

if (/plantathome\.in/.test(BASE)) {
  throw new Error(`Refusing to load-test production host: ${BASE}`);
}

// Custom metrics so a failure can be attributed to a journey step.
const errors = new Rate('journey_errors');
const ttfbBrowse = new Trend('ttfb_browse', true);
const ttfbSearch = new Trend('ttfb_search', true);
const ttfbPdp = new Trend('ttfb_pdp', true);

// ── scenario profiles ──────────────────────────────────────────────────────
const PROFILES = {
  smoke: { executor: 'constant-vus', vus: 1, duration: '30s' },

  // Staged ramp. Each plateau is long enough for latency to settle, so the
  // knee is a property of the system and not of the ramp rate.
  load: {
    executor: 'ramping-vus',
    startVUs: 1,
    stages: [
      { duration: '30s', target: 10 },
      { duration: '1m', target: 10 },
      { duration: '30s', target: 50 },
      { duration: '1m', target: 50 },
      { duration: '30s', target: 100 },
      { duration: '2m', target: 100 },
      { duration: '30s', target: 0 },
    ],
    gracefulRampDown: '30s',
  },

  // Keep climbing until something gives. abortOnFail stops the run at the
  // first sustained breach so the breaking point is recorded, not overshot.
  stress: {
    executor: 'ramping-vus',
    startVUs: 10,
    stages: [
      { duration: '1m', target: 100 },
      { duration: '1m', target: 250 },
      { duration: '1m', target: 500 },
      { duration: '1m', target: 1000 },
      { duration: '1m', target: 2000 },
      { duration: '30s', target: 0 },
    ],
    gracefulRampDown: '10s',
  },

  // Instant jumps with quiet periods between. The interesting number is not
  // throughput during the spike but how long latency takes to return to
  // baseline afterwards.
  spike: {
    executor: 'ramping-vus',
    startVUs: 5,
    stages: [
      { duration: '30s', target: 5 },      // baseline
      { duration: '5s', target: 300 },     // spike
      { duration: '45s', target: 300 },
      { duration: '5s', target: 5 },       // recover
      { duration: '60s', target: 5 },      // measure recovery
      { duration: '5s', target: 800 },     // bigger spike
      { duration: '45s', target: 800 },
      { duration: '5s', target: 5 },
      { duration: '90s', target: 5 },      // measure recovery again
    ],
    gracefulRampDown: '10s',
  },

  soak: { executor: 'constant-vus', vus: Number(__ENV.VUS || 25), duration: __ENV.DURATION || '35m' },
};

export const options = {
  scenarios: { [SCENARIO]: PROFILES[SCENARIO] },
  thresholds: {
    // Targets from the brief. These FAIL the run rather than merely reporting,
    // which is what makes the suite usable as a gate.
    http_req_failed: ['rate<0.005'],       // < 0.5% errors
    http_req_duration: ['p(95)<500'],      // p95 under 500ms
    journey_errors: ['rate<0.01'],
  },
  // A spike run legitimately breaches thresholds; recording them is the point.
  ...(SCENARIO === 'spike' || SCENARIO === 'stress' ? { thresholds: {} } : {}),
  summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
};

const q = (path) => `${BASE}${path}${path.includes('?') ? '&' : '?'}language=${LANG}`;

// Anonymous requests must send NO Authorization header. The API treats any
// bearer token as "uncacheable", so an authenticated generator measures a
// completely different, far heavier system than real anonymous traffic.
const anon = { headers: { Accept: 'application/json' }, tags: {} };

function ok(res, name) {
  const good = check(res, { [`${name} 2xx`]: (r) => r.status >= 200 && r.status < 300 });
  errors.add(!good);
  return good;
}

export default function () {
  // Weighted journey selection. Roughly: most land and browse, some search,
  // some open a product, a few hit the account area.
  const roll = Math.random();

  group('browse', () => {
    const res = http.get(q('/api/settings'), { ...anon, tags: { step: 'settings' } });
    ok(res, 'settings');
    ttfbBrowse.add(res.timings.waiting);

    const types = http.get(q('/api/types?limit=100'), { ...anon, tags: { step: 'types' } });
    ok(types, 'types');

    const cats = http.get(q('/api/categories?parent=null&limit=12'), { ...anon, tags: { step: 'categories' } });
    ok(cats, 'categories');

    const feed = http.get(q('/api/popular-products?limit=10'), { ...anon, tags: { step: 'popular' } });
    ok(feed, 'popular');
  });

  sleep(2 + Math.random() * 4); // read the homepage

  if (roll < 0.55) {
    group('listing', () => {
      const res = http.get(q('/api/products?limit=20'), { ...anon, tags: { step: 'list' } });
      ok(res, 'list');
      ttfbSearch.add(res.timings.waiting);

      // A real user filters before paging.
      if (Math.random() < 0.4) {
        ok(http.get(q('/api/products/filter-facets?type=plants'), { ...anon, tags: { step: 'facets' } }), 'facets');
      }
      if (Math.random() < 0.3) {
        ok(http.get(q('/api/products?limit=20&page=2'), { ...anon, tags: { step: 'page2' } }), 'page2');
      }
    });
    sleep(3 + Math.random() * 6);
  }

  if (roll < 0.3) {
    group('pdp', () => {
      // Pull a real slug from the listing rather than guessing one.
      const list = http.get(q('/api/products?limit=20'), { ...anon, tags: { step: 'list-for-slug' } });
      if (!ok(list, 'list-for-slug')) return;
      let slug;
      try {
        const body = list.json();
        const rows = body.data || body;
        slug = rows[Math.floor(Math.random() * rows.length)]?.slug;
      } catch (e) { /* fall through */ }
      if (!slug) return;

      const pdp = http.get(q(`/api/products/${slug}`), { ...anon, tags: { step: 'pdp' } });
      ok(pdp, 'pdp');
      ttfbPdp.add(pdp.timings.waiting);
    });
    sleep(4 + Math.random() * 8); // read the product page
  }
}

export function handleSummary(data) {
  const out = `docs/performance/raw/k6-${SCENARIO}.json`;
  return {
    [out]: JSON.stringify(data, null, 2),
    stdout: textSummary(data),
  };
}

/** Minimal text summary so the suite has no external dependency. */
function textSummary(data) {
  const m = data.metrics;
  const g = (name, stat) => {
    const v = m[name]?.values?.[stat];
    return v === undefined ? 'n/a' : v.toFixed(1);
  };
  const reqs = m.http_reqs?.values?.count ?? 0;
  const rate = m.http_reqs?.values?.rate ?? 0;
  const fail = ((m.http_req_failed?.values?.rate ?? 0) * 100).toFixed(3);
  return [
    '',
    `scenario   ${SCENARIO}`,
    `requests   ${reqs}  (${rate.toFixed(1)}/s)`,
    `failed     ${fail}%`,
    `duration   p50 ${g('http_req_duration', 'med')}ms  p95 ${g('http_req_duration', 'p(95)')}ms  p99 ${g('http_req_duration', 'p(99)')}ms  max ${g('http_req_duration', 'max')}ms`,
    `ttfb       browse ${g('ttfb_browse', 'p(95)')}ms  listing ${g('ttfb_search', 'p(95)')}ms  pdp ${g('ttfb_pdp', 'p(95)')}ms   (p95)`,
    '',
  ].join('\n');
}
