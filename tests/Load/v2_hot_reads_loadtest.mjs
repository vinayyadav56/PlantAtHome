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
const BUDGET_P95_MS = 500;

const ENDPOINTS = [
  '/api/v1/health',
  '/api/v1/catalog/products?limit=20',
  '/api/v1/catalog/categories',
  '/api/v1/search?q=plant&limit=20',
  '/api/v1/cms/banners',
];

const pct = (sorted, p) => sorted[Math.min(sorted.length - 1, Math.floor((p / 100) * sorted.length))];

async function hammer(pathname) {
  const latencies = [];
  let errors = 0;
  const deadline = Date.now() + DURATION_MS;
  const worker = async () => {
    while (Date.now() < deadline) {
      const t0 = performance.now();
      try {
        const res = await fetch(BASE + pathname, { signal: AbortSignal.timeout(10_000) });
        if (res.status >= 500) errors++;
        await res.arrayBuffer();
        latencies.push(performance.now() - t0);
      } catch {
        errors++;
      }
    }
  };
  await Promise.all(Array.from({ length: CONCURRENCY }, worker));
  latencies.sort((a, b) => a - b);
  return {
    pathname,
    requests: latencies.length,
    errors,
    rps: +(latencies.length / (DURATION_MS / 1000)).toFixed(1),
    p50: Math.round(pct(latencies, 50)),
    p95: Math.round(pct(latencies, 95)),
    p99: Math.round(pct(latencies, 99)),
    pass: pct(latencies, 95) < BUDGET_P95_MS && errors === 0,
  };
}

console.log(`v2 hot-read load sanity → ${BASE} (${CONCURRENCY} concurrent, ${DURATION_MS / 1000}s per endpoint, budget p95<${BUDGET_P95_MS}ms)`);
let allPass = true;
for (const ep of ENDPOINTS) {
  const r = await hammer(ep);
  allPass &&= r.pass;
  console.log(
    `  [${r.pass ? 'PASS' : 'FAIL'}] ${r.pathname.padEnd(40)} ${String(r.requests).padStart(6)} req @ ${String(r.rps).padStart(7)} rps · p50 ${r.p50}ms · p95 ${r.p95}ms · p99 ${r.p99}ms · err ${r.errors}`,
  );
}
console.log(allPass ? 'LOAD SANITY PASS' : 'LOAD SANITY FAIL');
process.exit(allPass ? 0 : 1);
