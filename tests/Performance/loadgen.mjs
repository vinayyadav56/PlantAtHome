#!/usr/bin/env node
/**
 * Closed-loop load generator.
 *
 * N virtual users each loop { request -> think } for the duration. Closed-loop
 * (not open-loop) because it mirrors how real browsers behave: a user cannot
 * issue their next request until the last one returns, so a slow server
 * naturally reduces offered load instead of building an unbounded queue.
 *
 * Reports p50/p90/p95/p99, throughput, error taxonomy, and — importantly — its
 * OWN saturation signal, so an apparent server knee can be distinguished from
 * the generator simply running out of capacity.
 *
 *   node loadgen.mjs --url=... --vus=50 --duration=20 --think=0 [--json=path]
 *
 * Refuses production hosts, matching the guard in the existing repo harness.
 */

import http from 'node:http';
import os from 'node:os';

const arg = (k, d) => {
  const hit = process.argv.find((a) => a.startsWith(`--${k}=`));
  return hit ? hit.split('=').slice(1).join('=') : d;
};

const URL_ = arg('url', 'http://127.0.0.1:8080/api/products?limit=20&language=en');
const VUS = parseInt(arg('vus', '50'), 10);
const DURATION_S = parseFloat(arg('duration', '20'));
const THINK_MS = parseFloat(arg('think', '0'));
const JSON_OUT = arg('json', null);
const LABEL = arg('label', '');

if (/plantathome\.in/.test(URL_)) {
  console.error('Refusing to load-test production host:', URL_);
  process.exit(2);
}

const target = new URL(URL_);
// One agent, unbounded sockets: the generator must never be the queue.
const agent = new http.Agent({ keepAlive: true, maxSockets: Infinity });

const lat = [];
let ok = 0;
let non2xx = 0;
const errs = new Map();
let bytes = 0;
let inFlight = 0;
let maxInFlight = 0;

const bump = (m) => errs.set(m, (errs.get(m) ?? 0) + 1);

function once() {
  return new Promise((resolve) => {
    const t0 = process.hrtime.bigint();
    inFlight++;
    if (inFlight > maxInFlight) maxInFlight = inFlight;

    const req = http.request(
      {
        hostname: target.hostname,
        port: target.port,
        path: target.pathname + target.search,
        method: 'GET',
        agent,
        headers: { Accept: 'application/json', Connection: 'keep-alive' },
      },
      (res) => {
        let n = 0;
        res.on('data', (c) => (n += c.length));
        res.on('end', () => {
          inFlight--;
          bytes += n;
          lat.push(Number(process.hrtime.bigint() - t0) / 1e6);
          if (res.statusCode >= 200 && res.statusCode < 300) ok++;
          else {
            non2xx++;
            bump(`HTTP ${res.statusCode}`);
          }
          resolve();
        });
      }
    );
    req.setTimeout(30000, () => {
      req.destroy(new Error('timeout'));
    });
    req.on('error', (e) => {
      inFlight--;
      bump(e.code || e.message);
      resolve();
    });
    req.end();
  });
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function vu(deadline) {
  while (Date.now() < deadline) {
    await once();
    if (THINK_MS) await sleep(THINK_MS);
  }
}

const pct = (sorted, p) =>
  sorted.length ? sorted[Math.min(sorted.length - 1, Math.floor((p / 100) * sorted.length))] : 0;

(async () => {
  // Warm the route so JIT/opcache/connection setup isn't charged to run 1.
  await once();
  lat.length = 0;
  ok = 0;
  non2xx = 0;
  bytes = 0;
  errs.clear();

  const cpu0 = process.cpuUsage();
  const t0 = Date.now();
  const deadline = t0 + DURATION_S * 1000;
  await Promise.all(Array.from({ length: VUS }, () => vu(deadline)));
  const elapsed = (Date.now() - t0) / 1000;
  const cpu = process.cpuUsage(cpu0);

  const s = [...lat].sort((a, b) => a - b);
  const total = ok + non2xx + [...errs.values()].reduce((a, b) => a + b, 0) - non2xx;
  const rps = lat.length / elapsed;

  // If the generator itself is pegged, the knee may be ours, not the server's.
  const genCpuPct = ((cpu.user + cpu.system) / 1e3 / (elapsed * 1000)) * 100;

  const out = {
    label: LABEL,
    url: URL_,
    vus: VUS,
    duration_s: +elapsed.toFixed(2),
    requests: lat.length,
    rps: +rps.toFixed(1),
    ok,
    non2xx,
    errors: Object.fromEntries(errs),
    error_rate: +(((non2xx + [...errs.values()].reduce((a, b) => a + b, 0)) / Math.max(1, lat.length)) * 100).toFixed(3),
    p50: +pct(s, 50).toFixed(1),
    p90: +pct(s, 90).toFixed(1),
    p95: +pct(s, 95).toFixed(1),
    p99: +pct(s, 99).toFixed(1),
    max: +(s[s.length - 1] ?? 0).toFixed(1),
    mbps: +((bytes / elapsed / 1048576) * 8).toFixed(2),
    max_in_flight: maxInFlight,
    generator_cpu_pct_of_one_core: +genCpuPct.toFixed(1),
    generator_cores: os.cpus().length,
  };

  if (JSON_OUT) {
    const fs = await import('node:fs');
    fs.writeFileSync(JSON_OUT, JSON.stringify(out, null, 2));
  }
  console.log(JSON.stringify(out));
})();
