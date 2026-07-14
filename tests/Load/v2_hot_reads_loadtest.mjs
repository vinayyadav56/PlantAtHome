#!/usr/bin/env node
/**
 * v2 Phase 12 load sanity — concurrent pass over the hot PUBLIC /api/v1 reads.
 * Not a benchmark rig: a smoke-level budget check recorded in
 * docs/V2_OPERATIONS.md. Budget: p95 < 500 ms per endpoint at ~50 concurrent.
 *
 *   node tests/Load/v2_hot_reads_loadtest.mjs [baseUrl]
 *   (default base: staging Railway)
 */
const BASE = process.argv[2] || 'https://plantathome-production.up.railway.app';
const CONCURRENCY = 50;
const DURATION_MS = 30_000;
// The budget self-calibrates: measured client→server RTT baseline (median of
// sequential health pings) + this server-time allowance. A fixed wall-clock
// budget from an arbitrary client punishes network distance, not the service.
const SERVER_BUDGET_MS = 500;
const MAX_TRANSPORT_ERR_RATE = 0.005; // timeouts/resets under saturation

const ENDPOINTS = [
  '/api/v1/health',
  '/api/v1/catalog/products?limit=20',
  '/api/v1/catalog/categories',
  '/api/v1/search?q=plant&limit=20',
  '/api/v1/cms/banners',
];

const pct = (sorted, p) => sorted[Math.min(sorted.length - 1, Math.floor((p / 100) * sorted.length))];

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

async function hammer(pathname, budgetMs) {
  const latencies = [];
  let serverErrors = 0;
  let transportErrors = 0;
  const deadline = Date.now() + DURATION_MS;
  const worker = async () => {
    while (Date.now() < deadline) {
      const t0 = performance.now();
      try {
        const res = await fetch(BASE + pathname, { signal: AbortSignal.timeout(10_000) });
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
    pathname,
    requests: latencies.length,
    serverErrors,
    transportErrors,
    rps: +(latencies.length / (DURATION_MS / 1000)).toFixed(1),
    p50: Math.round(pct(latencies, 50)),
    p95: Math.round(pct(latencies, 95)),
    p99: Math.round(pct(latencies, 99)),
    pass:
      pct(latencies, 95) < budgetMs &&
      serverErrors === 0 &&
      transportErrors / Math.max(1, total) <= MAX_TRANSPORT_ERR_RATE,
  };
}

const rtt = await measureRttBaseline();
const budget = rtt + SERVER_BUDGET_MS;
console.log(
  `v2 hot-read load sanity → ${BASE}\n` +
    `  ${CONCURRENCY} concurrent · ${DURATION_MS / 1000}s/endpoint · RTT baseline ${rtt}ms → p95 budget ${budget}ms · zero 5xx · transport errs ≤${MAX_TRANSPORT_ERR_RATE * 100}%`,
);
let allPass = true;
for (const ep of ENDPOINTS) {
  const r = await hammer(ep, budget);
  allPass &&= r.pass;
  console.log(
    `  [${r.pass ? 'PASS' : 'FAIL'}] ${r.pathname.padEnd(40)} ${String(r.requests).padStart(6)} req @ ${String(r.rps).padStart(7)} rps · p50 ${r.p50}ms · p95 ${r.p95}ms · p99 ${r.p99}ms · 5xx ${r.serverErrors} · neterr ${r.transportErrors}`,
  );
}
console.log(allPass ? 'LOAD SANITY PASS' : 'LOAD SANITY FAIL');
process.exit(allPass ? 0 : 1);
