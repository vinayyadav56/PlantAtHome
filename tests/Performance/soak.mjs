#!/usr/bin/env node
/**
 * Soak / endurance runner.
 *
 * Holds a steady load and samples BOTH client-side latency and server-side
 * resource state on a fixed interval, so slow degradation is visible as a trend
 * rather than as a single end-of-run average. That trend is the whole point —
 * a leak shows up as a slope, and an average hides it.
 *
 * Sampled per interval:
 *   - p50/p95/p99 and RPS for that window only (not cumulative)
 *   - resident memory of the PHP worker pool
 *   - MySQL open connections and the size of the `cache` and `jobs` tables
 *
 *   node soak.mjs --url=... --vus=30 --minutes=35 --interval=15 --json=path
 */

import http from 'node:http';
import { execSync } from 'node:child_process';
import fs from 'node:fs';

const arg = (k, d) => {
  const hit = process.argv.find((a) => a.startsWith(`--${k}=`));
  return hit ? hit.split('=').slice(1).join('=') : d;
};

const URL_ = arg('url', 'http://127.0.0.1:8080/api/products?limit=20&language=en');
const VUS = parseInt(arg('vus', '30'), 10);
const MINUTES = parseFloat(arg('minutes', '35'));
const INTERVAL_S = parseFloat(arg('interval', '15'));
const JSON_OUT = arg('json', 'docs/performance/raw/soak.json');
const DB = arg('db', 'pah_perf');

if (/plantathome\.in/.test(URL_)) {
  console.error('Refusing to load-test production host:', URL_);
  process.exit(2);
}

const target = new URL(URL_);
const agent = new http.Agent({ keepAlive: true, maxSockets: Infinity });

let win = [];        // latencies in the current window
let winOk = 0, winErr = 0;
let totalReq = 0, totalErr = 0;

function once() {
  return new Promise((resolve) => {
    const t0 = process.hrtime.bigint();
    const req = http.request(
      {
        hostname: target.hostname, port: target.port,
        path: target.pathname + target.search, method: 'GET', agent,
        headers: { Accept: 'application/json', Connection: 'keep-alive' },
      },
      (res) => {
        res.on('data', () => {});
        res.on('end', () => {
          win.push(Number(process.hrtime.bigint() - t0) / 1e6);
          totalReq++;
          if (res.statusCode >= 200 && res.statusCode < 300) winOk++;
          else { winErr++; totalErr++; }
          resolve();
        });
      }
    );
    req.setTimeout(30000, () => req.destroy(new Error('timeout')));
    req.on('error', () => { winErr++; totalErr++; totalReq++; resolve(); });
    req.end();
  });
}

// ── server-side samplers ───────────────────────────────────────────────────
const mysqlCreds = (() => {
  const env = fs.readFileSync('.env', 'utf8');
  const g = (k) => (env.match(new RegExp(`^${k}=(.*)$`, 'm'))?.[1] ?? '').replace(/^["']|["']$/g, '');
  return { u: g('DB_USERNAME'), p: g('DB_PASSWORD') };
})();

function sql(q) {
  try {
    return execSync(
      `mysql -u"${mysqlCreds.u}" -p"${mysqlCreds.p}" -N -e ${JSON.stringify(q)} 2>/dev/null`,
      { encoding: 'utf8' }
    ).trim();
  } catch { return ''; }
}

function serverSample() {
  // Resident memory of the whole PHP worker pool, in MB.
  let rssMb = null;
  try {
    const out = execSync("ps -o rss= -p $(pgrep -f 'S 127.0.0.1:8080' | tr '\\n' ',' | sed 's/,$//')", { encoding: 'utf8' });
    rssMb = out.trim().split('\n').reduce((a, l) => a + parseInt(l.trim() || '0', 10), 0) / 1024;
  } catch { /* pool gone */ }

  const conns = parseInt(sql("SHOW STATUS LIKE 'Threads_connected'").split('\t')[1] || '0', 10);
  const cacheRows = parseInt(sql(`SELECT COUNT(*) FROM ${DB}.cache`) || '0', 10);
  const jobRows = parseInt(sql(`SELECT COUNT(*) FROM ${DB}.jobs`) || '0', 10);

  return { rss_mb: rssMb == null ? null : +rssMb.toFixed(1), mysql_threads: conns, cache_rows: cacheRows, job_rows: jobRows };
}

const pct = (s, p) => (s.length ? s[Math.min(s.length - 1, Math.floor((p / 100) * s.length))] : 0);

// ── run ────────────────────────────────────────────────────────────────────
const samples = [];
const started = Date.now();
const deadline = started + MINUTES * 60000;
let stop = false;

async function vu() {
  while (!stop && Date.now() < deadline) await once();
}

const sampler = setInterval(() => {
  const s = [...win].sort((a, b) => a - b);
  const secs = INTERVAL_S;
  samples.push({
    t_min: +((Date.now() - started) / 60000).toFixed(2),
    rps: +(win.length / secs).toFixed(1),
    p50: +pct(s, 50).toFixed(1),
    p95: +pct(s, 95).toFixed(1),
    p99: +pct(s, 99).toFixed(1),
    errors: winErr,
    ...serverSample(),
  });
  const last = samples[samples.length - 1];
  console.error(
    `t+${String(last.t_min).padStart(5)}m  rps=${String(last.rps).padStart(6)}  ` +
    `p50=${String(last.p50).padStart(6)}  p95=${String(last.p95).padStart(7)}  ` +
    `rss=${String(last.rss_mb).padStart(7)}MB  conns=${last.mysql_threads}  cache=${last.cache_rows}  err=${last.errors}`
  );
  win = []; winOk = 0; winErr = 0;
}, INTERVAL_S * 1000);

console.error(`soak: ${VUS} VUs for ${MINUTES} min, sampling every ${INTERVAL_S}s -> ${JSON_OUT}`);
await Promise.all(Array.from({ length: VUS }, vu));
stop = true;
clearInterval(sampler);

// ── trend analysis: least-squares slope over the run ───────────────────────
function slope(key) {
  const pts = samples.map((s, i) => [i, s[key]]).filter(([, y]) => typeof y === 'number');
  if (pts.length < 3) return null;
  const n = pts.length;
  const sx = pts.reduce((a, [x]) => a + x, 0);
  const sy = pts.reduce((a, [, y]) => a + y, 0);
  const sxy = pts.reduce((a, [x, y]) => a + x * y, 0);
  const sxx = pts.reduce((a, [x]) => a + x * x, 0);
  return +(((n * sxy - sx * sy) / (n * sxx - sx * sx)) * (60 / INTERVAL_S)).toFixed(3); // per minute
}

const result = {
  url: URL_, vus: VUS, minutes: MINUTES, interval_s: INTERVAL_S,
  total_requests: totalReq, total_errors: totalErr,
  error_rate: +((totalErr / Math.max(1, totalReq)) * 100).toFixed(3),
  samples,
  trends_per_minute: {
    rss_mb: slope('rss_mb'),
    p95_ms: slope('p95'),
    mysql_threads: slope('mysql_threads'),
    cache_rows: slope('cache_rows'),
    job_rows: slope('job_rows'),
    rps: slope('rps'),
  },
};

fs.writeFileSync(JSON_OUT, JSON.stringify(result, null, 2));
console.error('\nTRENDS (change per minute, least squares over the whole run)');
for (const [k, v] of Object.entries(result.trends_per_minute)) {
  console.error(`  ${k.padEnd(16)} ${v === null ? 'n/a' : (v > 0 ? '+' : '') + v}`);
}
console.error(`\ntotal ${totalReq} requests, ${result.error_rate}% errors -> ${JSON_OUT}`);
