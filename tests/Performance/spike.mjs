#!/usr/bin/env node
/**
 * Spike runner.
 *
 * Steps VU count instantly rather than ramping, then drops back to baseline and
 * keeps measuring. The interesting figure is not throughput during the spike —
 * a saturated server has a known throughput — it is RECOVERY: how long after the
 * spike ends does latency return to its pre-spike level.
 *
 * Reports, per phase: RPS, p50/p95/p99, errors. Then computes recovery time by
 * finding the first post-spike sample within 20% of the measured baseline p95.
 *
 *   node spike.mjs --url=... --baseline=5 --spikes=100,500,1000 --json=path
 */

import http from 'node:http';
import fs from 'node:fs';

const arg = (k, d) => {
  const hit = process.argv.find((a) => a.startsWith(`--${k}=`));
  return hit ? hit.split('=').slice(1).join('=') : d;
};

const URL_ = arg('url', 'http://127.0.0.1:8080/api/products?limit=20&language=en');
const BASELINE = parseInt(arg('baseline', '5'), 10);
const SPIKES = arg('spikes', '100,500,1000').split(',').map(Number);
const BASE_S = parseFloat(arg('baseSeconds', '15'));
const SPIKE_S = parseFloat(arg('spikeSeconds', '20'));
const RECOVER_S = parseFloat(arg('recoverSeconds', '30'));
const JSON_OUT = arg('json', 'docs/performance/raw/spike.json');

if (/plantathome\.in/.test(URL_)) {
  console.error('Refusing to load-test production host:', URL_);
  process.exit(2);
}

const target = new URL(URL_);
const agent = new http.Agent({ keepAlive: true, maxSockets: Infinity });

let bucket = [];
let bucketErr = 0;

function once() {
  return new Promise((resolve) => {
    const t0 = process.hrtime.bigint();
    const req = http.request(
      { hostname: target.hostname, port: target.port, path: target.pathname + target.search,
        method: 'GET', agent, headers: { Accept: 'application/json', Connection: 'keep-alive' } },
      (res) => {
        res.on('data', () => {});
        res.on('end', () => {
          bucket.push(Number(process.hrtime.bigint() - t0) / 1e6);
          if (res.statusCode < 200 || res.statusCode >= 300) bucketErr++;
          resolve();
        });
      }
    );
    req.setTimeout(30000, () => req.destroy(new Error('timeout')));
    req.on('error', () => { bucketErr++; resolve(); });
    req.end();
  });
}

const pct = (s, p) => (s.length ? s[Math.min(s.length - 1, Math.floor((p / 100) * s.length))] : 0);

/** Run `vus` concurrent loops for `seconds`, sampling every second. */
async function phase(label, vus, seconds, series) {
  const stopAt = Date.now() + seconds * 1000;
  let stop = false;
  const tick = setInterval(() => {
    const s = [...bucket].sort((a, b) => a - b);
    series.push({
      phase: label, vus,
      t: +((Date.now() - T0) / 1000).toFixed(1),
      rps: bucket.length,
      p50: +pct(s, 50).toFixed(1),
      p95: +pct(s, 95).toFixed(1),
      p99: +pct(s, 99).toFixed(1),
      errors: bucketErr,
    });
    bucket = []; bucketErr = 0;
  }, 1000);

  const worker = async () => { while (!stop && Date.now() < stopAt) await once(); };
  await Promise.all(Array.from({ length: vus }, worker));
  stop = true;
  clearInterval(tick);
}

const T0 = Date.now();
const series = [];

console.error(`spike: baseline ${BASELINE} VUs, spikes ${SPIKES.join(' -> ')}\n`);
console.error('phase        vus     rps     p50     p95     p99   err');

const printer = setInterval(() => {
  const last = series[series.length - 1];
  if (!last) return;
  console.error(
    `${last.phase.padEnd(11)}${String(last.vus).padStart(4)}${String(last.rps).padStart(8)}` +
    `${String(last.p50).padStart(8)}${String(last.p95).padStart(8)}${String(last.p99).padStart(8)}` +
    `${String(last.errors).padStart(6)}`
  );
}, 1000);

await phase('baseline', BASELINE, BASE_S, series);
const baseP95 = median(series.filter((s) => s.phase === 'baseline').map((s) => s.p95));

const results = [];
for (const vus of SPIKES) {
  const spikeStart = series.length;
  await phase(`spike-${vus}`, vus, SPIKE_S, series);
  const recStart = series.length;
  await phase(`recover-${vus}`, BASELINE, RECOVER_S, series);

  const spikeRows = series.slice(spikeStart, recStart);
  const recRows = series.slice(recStart);
  // Recovery = first post-spike second whose p95 is within 20% of baseline.
  const threshold = baseP95 * 1.2;
  const idx = recRows.findIndex((r) => r.p95 > 0 && r.p95 <= threshold);

  results.push({
    spike_vus: vus,
    spike_rps: Math.round(median(spikeRows.map((r) => r.rps))),
    spike_p95: Math.round(median(spikeRows.map((r) => r.p95))),
    spike_p99: Math.round(Math.max(...spikeRows.map((r) => r.p99))),
    spike_errors: spikeRows.reduce((a, r) => a + r.errors, 0),
    recovery_s: idx === -1 ? null : idx + 1,
    recovered: idx !== -1,
  });
}

clearInterval(printer);

function median(a) { const s = [...a].sort((x, y) => x - y); return s.length ? s[Math.floor(s.length / 2)] : 0; }

const out = { url: URL_, baseline_vus: BASELINE, baseline_p95_ms: +baseP95.toFixed(1), results, series };
fs.writeFileSync(JSON_OUT, JSON.stringify(out, null, 2));

console.error(`\nbaseline p95 ${baseP95.toFixed(1)}ms\n`);
console.error('spike VUs    RPS   p95      p99 max   errors   recovery');
for (const r of results) {
  console.error(
    String(r.spike_vus).padStart(9) + String(r.spike_rps).padStart(7) +
    String(r.spike_p95).padStart(6) + 'ms' + String(r.spike_p99).padStart(9) + 'ms' +
    String(r.spike_errors).padStart(9) +
    (r.recovered ? `   ${r.recovery_s}s` : '   did not recover')
  );
}
console.error(`\nwrote ${JSON_OUT}`);
