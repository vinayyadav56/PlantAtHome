#!/usr/bin/env node
/**
 * Cross-API hot-read load sanity — legacy /api + v2 /api/v1 on STAGING.
 *
 * Hammers the hot PUBLIC reads that every storefront page depends on, at
 * ~50 concurrent for ~20s each, and reports per-endpoint p50/p95/p99,
 * throughput, and 5xx / transport-error rates.
 *
 * The p95 budget SELF-CALIBRATES: measured client->server RTT baseline
 * (median of sequential health pings) + a fixed server-time allowance, so a
 * far-away client's network distance is not charged against the service.
 *
 *   node tests/Load/both_apis_hot_reads_loadtest.mjs [baseUrl]
 *
 * SAFETY: staging only. GET-only. Never point at production.
 */
const BASE = process.argv[2] || 'https://plantathome-production.up.railway.app';
const CONCURRENCY = Number(process.env.CONCURRENCY || 50);
const DURATION_MS = Number(process.env.DURATION_MS || 20_000);
const SERVER_BUDGET_MS = 700; // server-time allowance on top of RTT baseline
const MAX_TRANSPORT_ERR_RATE = 0.005;

if (/plantathome\.in/.test(BASE)) {
  console.error('Refusing to load-test production host:', BASE);
  process.exit(2);
}

// [label, path] — legacy first, then v2. All public GET reads.
const ENDPOINTS = [
  ['legacy settings', '/api/settings'],
  ['legacy products', '/api/products?limit=20'],
  ['legacy categories', '/api/categories?limit=20'],
  ['legacy search', '/api/products?search=type.slug:plant&limit=20'],
  ['v2 health', '/api/v1/health'],
  ['v2 catalog/products', '/api/v1/catalog/products?limit=20'],
  ['v2 catalog/categories', '/api/v1/catalog/categories'],
  ['v2 search', '/api/v1/search?q=plant&limit=20'],
  ['v2 cms/banners', '/api/v1/cms/banners'],
];

const pct = (s, p) => s[Math.min(s.length - 1, Math.floor((p / 100) * s.length))] ?? 0;

async function measureRttBaseline() {
  const samples = [];
  for (let i = 0; i < 10; i++) {
    const t0 = performance.now();
    try {
      await (await fetch(BASE + '/api/v1/health', { signal: AbortSignal.timeout(10_000) })).arrayBuffer();
      samples.push(performance.now() - t0);
    } catch {}
  }
  samples.sort((a, b) => a - b);
  return Math.round(samples[Math.floor(samples.length / 2)] ?? 0);
}

async function hammer(label, pathname, budgetMs) {
  const latencies = [];
  const statusCounts = {};
  let serverErrors = 0;
  let transportErrors = 0;
  const deadline = Date.now() + DURATION_MS;
  const worker = async () => {
    while (Date.now() < deadline) {
      const t0 = performance.now();
      try {
        const res = await fetch(BASE + pathname, { signal: AbortSignal.timeout(10_000) });
        statusCounts[res.status] = (statusCounts[res.status] || 0) + 1;
        if (res.status >= 500) serverErrors++;
        await res.arrayBuffer();
        latencies.push(performance.now() - t0);
      } catch {
        transportErrors++;
      }
    }
  };
  await Promise.all(Array.from({ length: CONCURRENCY }, worker));
  latencies.sort((a, b) => a - b);
  const total = latencies.length + transportErrors;
  return {
    label,
    pathname,
    requests: latencies.length,
    statusCounts,
    serverErrors,
    transportErrors,
    rps: +(latencies.length / (DURATION_MS / 1000)).toFixed(1),
    p50: Math.round(pct(latencies, 50)),
    p95: Math.round(pct(latencies, 95)),
    p99: Math.round(pct(latencies, 99)),
    max: Math.round(latencies[latencies.length - 1] ?? 0),
    pass:
      pct(latencies, 95) < budgetMs &&
      serverErrors === 0 &&
      transportErrors / Math.max(1, total) <= MAX_TRANSPORT_ERR_RATE,
  };
}

const rtt = await measureRttBaseline();
const budget = rtt + SERVER_BUDGET_MS;
console.log(
  `cross-API hot-read load sanity -> ${BASE}\n` +
    `  ${CONCURRENCY} concurrent · ${DURATION_MS / 1000}s/endpoint · RTT baseline ${rtt}ms -> p95 budget ${budget}ms · zero 5xx · transport errs <=${MAX_TRANSPORT_ERR_RATE * 100}%`,
);
const results = [];
let allPass = true;
for (const [label, ep] of ENDPOINTS) {
  const r = await hammer(label, ep, budget);
  results.push(r);
  allPass &&= r.pass;
  console.log(
    `  [${r.pass ? 'PASS' : 'FAIL'}] ${label.padEnd(22)} ${String(r.requests).padStart(6)} req @ ${String(r.rps).padStart(7)} rps · p50 ${r.p50}ms · p95 ${r.p95}ms · p99 ${r.p99}ms · max ${r.max}ms · 5xx ${r.serverErrors} · neterr ${r.transportErrors} · ${JSON.stringify(r.statusCounts)}`,
  );
}
console.log(allPass ? 'LOAD SANITY PASS' : 'LOAD SANITY FAIL');
console.log('JSON ' + JSON.stringify({ base: BASE, rtt, budget, results }));
process.exit(allPass ? 0 : 1);
