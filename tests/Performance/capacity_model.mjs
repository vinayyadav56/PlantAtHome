#!/usr/bin/env node
/**
 * Capacity model.
 *
 * Turns the MEASURED per-request constants into an infrastructure estimate for
 * a range of concurrent-user targets. Every input is declared at the top so a
 * reader can substitute their own and re-run: the point is auditability, not
 * a magic number.
 *
 *   node capacity_model.mjs [--json=path]
 *
 * Method: Little's Law. For a closed system,
 *     concurrent users  =  arrival rate x time in system
 * so, rearranged, the request rate a population generates is
 *     RPS  =  users / (think time + response time)
 * plus any background traffic each user generates regardless of interaction.
 */

const arg = (k, d) => {
  const hit = process.argv.find((a) => a.startsWith(`--${k}=`));
  return hit ? hit.split('=').slice(1).join('=') : d;
};

// ─────────────────────────────────────────────────────────── MEASURED inputs
const M = {
  // Local rig: 8 CLI-server workers, 8-core M-series, opcache on, warm cache.
  rps_per_core_measured: 487 / 8,       // /api/health floor  = 60.9
  rps_per_core_heaviest: 364 / 8,       // products?limit=100 = 45.5
  rps_per_core_typical: 407 / 8,        // products?limit=20  = 50.9
  knee_vus: 10,                          // throughput plateaued here
  p50_at_knee_ms: 20.4,
  // Payload sizes actually returned (bytes)
  bytes_products20: 35.6 * 1024,
  bytes_products100: 170.5 * 1024,
  bytes_settings: 5.1 * 1024,
};

// ─────────────────────────────────────────────────────── ASSUMED user model
// These are the numbers to argue with. They are NOT measured.
const A = {
  // Storefront behaviour. 30s is a browsing cadence for a retail catalogue —
  // people read the page. An 8s think time models frantic clicking and inflates
  // every downstream number by ~4x, so it is the wrong default.
  think_time_s: 30,
  api_calls_per_page: 4,           // measured: the home SSR path fans out to 4-5 upstream calls

  // CDN offload. The API already emits `s-maxage=300, stale-while-revalidate`
  // on the public read endpoints (ApiResponseCache), so with a CDN in front,
  // most anonymous GETs never reach the origin at all. This factor is the single
  // biggest lever in the model and the one most worth verifying empirically.
  cdn_offload_anonymous: 0.8,

  // Cart poll: MEASURED in the code (7s interval, authenticated users only).
  // It carries a bearer token, so it is uncacheable by definition — 100% of it
  // reaches the origin no matter what the CDN does.
  cart_poll_interval_s: 7,
  authenticated_share: 0.35,

  cache_hit_rate: 0.9,             // origin-side response cache, affects DB not RPS
  // Production efficiency vs this laptop rig. php-fpm + opcache + preload on a
  // dedicated vCPU is generally faster than PHP's CLI server; 1.0 = same.
  prod_efficiency_factor: 1.0,
  // Headroom: never plan to run a tier at 100% of measured capacity.
  target_utilisation: 0.65,
};

const TARGETS = [10e3, 25e3, 50e3, 75e3, 100e3, 200e3, 500e3, 1e6];

// ────────────────────────────────────────────────────────────────── pricing
// ap-south-1 on-demand, USD/month, 730h. Indicative only — verify before use.
const PRICE = {
  vcpu_hour_c7g: 0.0361,           // c7g.large = 2 vCPU / 4 GB @ $0.0722/h
  rds_hour_r7g_large: 0.2320,      // 2 vCPU / 16 GB
  rds_hour_r7g_2xl: 0.9280,        // 8 vCPU / 64 GB
  elasticache_hour_r7g_large: 0.2260,
  alb_hour: 0.0225,
  alb_lcu_hour: 0.008,
  cloudfront_per_gb: 0.109,        // India edge
  s3_per_gb_month: 0.025,
  data_transfer_per_gb: 0.1093,
};
const H = 730;

// ───────────────────────────────────────────────────────────────── the model
function model(users, over = {}) {
  const a = { ...A, ...over };

  // 1. Deliberate page-view traffic, at the edge.
  const edgeInteractiveRps = (users / a.think_time_s) * a.api_calls_per_page;
  // ...and what survives the CDN to hit the origin.
  const originInteractiveRps = edgeInteractiveRps * (1 - a.cdn_offload_anonymous);

  // 2. Background traffic that happens whether or not the user acts. The cart
  //    poll is authenticated, so it is uncacheable and ALL of it hits origin.
  const pollingRps = (users * a.authenticated_share) / a.cart_poll_interval_s;

  const originRps = originInteractiveRps + pollingRps;
  const edgeRps = edgeInteractiveRps + pollingRps;

  // 3. Only cache MISSES reach the DB in full.
  const missRps = originRps * (1 - a.cache_hit_rate);

  // 4. App tier sized on ORIGIN rps at target utilisation.
  const perCore = M.rps_per_core_typical * a.prod_efficiency_factor;
  const appCores = Math.ceil(originRps / (perCore * a.target_utilisation));

  // 5. Bandwidth at the edge (what users actually download).
  const gbPerMonth = (edgeRps * M.bytes_products20 * 2592000) / 1024 ** 3;

  // 6. DB: each cache miss costs ~65 queries on the list path post-fix.
  const dbQps = missRps * 65;

  return {
    users, edgeInteractiveRps, originInteractiveRps, pollingRps,
    edgeRps, originRps, missRps, appCores, gbPerMonth, dbQps,
    poll_share_of_origin: pollingRps / originRps,
  };
}

function infra(m) {
  // App: c7g.large = 2 vCPU each.
  const appInstances = Math.max(2, Math.ceil(m.appCores / 2));
  // DB: r7g.large to ~8k qps, then 2xlarge, then add read replicas.
  let dbClass = 'r7g.large', dbHour = PRICE.rds_hour_r7g_large, replicas = 0;
  if (m.dbQps > 8000) { dbClass = 'r7g.2xlarge'; dbHour = PRICE.rds_hour_r7g_2xl; }
  if (m.dbQps > 25000) replicas = Math.ceil((m.dbQps - 25000) / 25000);
  // Redis: one node to 100k ops/s, then cluster.
  const redisNodes = Math.max(1, Math.ceil(m.originRps / 100000));
  // CDN absorbs static + cacheable API; assume 70% of bytes served from edge.
  const cdnGb = m.gbPerMonth * 0.7;
  const originGb = m.gbPerMonth * 0.3;

  const cost =
    appInstances * 2 * PRICE.vcpu_hour_c7g * H +
    (1 + replicas) * dbHour * H +
    redisNodes * PRICE.elasticache_hour_r7g_large * H +
    PRICE.alb_hour * H + (m.originRps / 100) * PRICE.alb_lcu_hour * H +
    cdnGb * PRICE.cloudfront_per_gb +
    originGb * PRICE.data_transfer_per_gb;

  return { appInstances, vcpu: appInstances * 2, ram: appInstances * 4, dbClass, replicas, redisNodes, cdnGb, cost };
}

const rows = TARGETS.map((u) => {
  const m = model(u);
  return { ...m, ...infra(m) };
});

const fmt = (n, d = 0) => n.toLocaleString('en-US', { maximumFractionDigits: d });

console.log('\nMEASURED constants');
console.log(`  framework floor          ${M.rps_per_core_measured.toFixed(1)} RPS/core   (/api/health, trivial handler)`);
console.log(`  typical endpoint         ${M.rps_per_core_typical.toFixed(1)} RPS/core   (/api/products?limit=20, warm)`);
console.log(`  heaviest endpoint        ${M.rps_per_core_heaviest.toFixed(1)} RPS/core   (/api/products?limit=100, warm)`);
console.log('\nASSUMED user model  (change these and re-run)');
console.log(`  think time ${A.think_time_s}s · ${A.api_calls_per_page} API calls/page · ${(A.authenticated_share * 100).toFixed(0)}% authenticated`);
console.log(`  cart poll every ${A.cart_poll_interval_s}s · cache hit ${(A.cache_hit_rate * 100).toFixed(0)}% · target utilisation ${(A.target_utilisation * 100).toFixed(0)}%`);

console.log('\n' + '='.repeat(120));
console.log(
  'users'.padStart(9) + 'edge RPS'.padStart(10) + 'origin'.padStart(9) + 'of which poll'.padStart(15) +
  'vCPU'.padStart(7) + 'app inst'.padStart(10) + 'database'.padStart(16) + 'TB/mo'.padStart(8) + '$/month'.padStart(11)
);
console.log('='.repeat(120));
for (const r of rows) {
  console.log(
    fmt(r.users).padStart(9) +
    fmt(r.edgeRps).padStart(10) +
    fmt(r.originRps).padStart(9) +
    `${fmt(r.pollingRps)} (${(r.poll_share_of_origin * 100).toFixed(0)}%)`.padStart(15) +
    fmt(r.vcpu).padStart(7) +
    fmt(r.appInstances).padStart(10) +
    (r.dbClass + (r.replicas ? ` +${r.replicas}r` : '')).padStart(16) +
    (r.gbPerMonth / 1024).toFixed(1).padStart(8) +
    ('$' + fmt(r.cost)).padStart(11)
  );
}
console.log('='.repeat(120));

const t100 = rows.find((r) => r.users === 100e3);
console.log(`\n100,000 concurrent users`);
console.log(`  ${fmt(t100.edgeRps)} RPS at the edge, of which ${fmt(t100.originRps)} RPS reaches the origin.`);
console.log(`  ${fmt(t100.pollingRps)} RPS of that origin load (${(t100.poll_share_of_origin * 100).toFixed(0)}%) is the 7-second cart poll,`);
console.log(`  which carries a bearer token and therefore cannot be cached at any layer.`);
console.log(`  At the measured ${M.rps_per_core_typical.toFixed(1)} RPS/core that needs ~${fmt(t100.appCores)} app cores.`);
console.log(`  Production today: ONE PM2 fork process per Next app, on ONE EC2 box.`);

// ── sensitivity: the two assumptions that dominate the answer ───────────────
console.log('\nSENSITIVITY at 100,000 users — app cores required');
console.log('(rows = think time, cols = CDN offload of anonymous GETs)\n');
const offloads = [0.0, 0.5, 0.8, 0.95];
const thinks = [10, 20, 30, 60];
console.log('think'.padStart(7) + offloads.map((o) => `${(o * 100).toFixed(0)}%`.padStart(10)).join(''));
for (const t of thinks) {
  const cells = offloads.map((o) => fmt(model(100e3, { think_time_s: t, cdn_offload_anonymous: o }).appCores).padStart(10));
  console.log(`${t}s`.padStart(7) + cells.join(''));
}
const noPoll = model(100e3, { cart_poll_interval_s: 1e9 });
console.log(`\nIf the 7s cart poll were removed entirely (push, or on-demand fetch):`);
console.log(`  origin ${fmt(t100.originRps)} -> ${fmt(noPoll.originRps)} RPS, app cores ${fmt(t100.appCores)} -> ${fmt(noPoll.appCores)}` +
            `  (${((1 - noPoll.appCores / t100.appCores) * 100).toFixed(0)}% less app tier)\n`);

const out = arg('json', null);
if (out) {
  const fs = await import('node:fs');
  fs.writeFileSync(out, JSON.stringify({ generated_at: new Date().toISOString(), measured: M, assumed: A, pricing: PRICE, rows }, null, 2));
  console.error(`wrote ${out}`);
}
