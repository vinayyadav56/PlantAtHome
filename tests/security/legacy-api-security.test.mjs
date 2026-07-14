// Legacy Marvel API security probe suite (STAGING ONLY).
// Run: node --test legacy-api-security.test.mjs
// Node 18+ (global fetch). Target defaults to staging; NEVER point at production.
//
// Covers: /token + /register rate-limiting effectiveness, order IDOR (unauth +
// cross-user + guest tracking_token), register privilege-escalation, user-profile
// mass-assignment, bulk-export authorization, SQL-injection on search/orderBy,
// APP_DEBUG / stack-trace leakage, CORS policy.
import { test } from 'node:test';
import assert from 'node:assert/strict';

const BASE = process.env.API_BASE || 'https://plantathome-production.up.railway.app/api';
assert.ok(!/plantathome\.in/.test(BASE), 'refusing to run against production');

const H = { 'Content-Type': 'application/json', Accept: 'application/json' };
const j = (o) => JSON.stringify(o);
const uniqEmail = (p) => `sec_${p}_${Date.now()}_${Math.random().toString(36).slice(2, 7)}@example.com`;

async function register(email, extra = {}) {
  return fetch(`${BASE}/register`, { method: 'POST', headers: H, body: j({ name: 'Probe', email, password: 'Passw0rd!', ...extra }) });
}
async function login(email, password = 'Passw0rd!') {
  const r = await fetch(`${BASE}/token`, { method: 'POST', headers: H, body: j({ email, password }) });
  return (await r.json()).token;
}
async function makeCustomer(tag) {
  const email = uniqEmail(tag);
  await register(email);
  return { email, token: await login(email) };
}

// ── Rate limiting: the throttle counter MUST actually decrement / trip 429 ──
test('login /token brute-force is rate limited', async () => {
  const codes = [];
  for (let i = 0; i < 25; i++) {
    const r = await fetch(`${BASE}/token`, { method: 'POST', headers: H, body: j({ email: 'brute@x.com', password: 'g' + i }) });
    codes.push(r.status);
  }
  // KNOWN STAGING DEFECT: throttle counter never persists -> no 429 ever.
  assert.ok(codes.includes(429), `expected a 429 within 25 rapid attempts, got ${JSON.stringify(codes)}`);
});

test('x-ratelimit-remaining decrements on repeated /token calls', async () => {
  const rem = [];
  for (let i = 0; i < 5; i++) {
    const r = await fetch(`${BASE}/token`, { method: 'POST', headers: H, body: j({ email: 'seq@x.com', password: 'g' }) });
    rem.push(Number(r.headers.get('x-ratelimit-remaining')));
  }
  assert.ok(rem[4] < rem[0], `remaining must drop; got ${JSON.stringify(rem)} (stuck value = broken limiter)`);
});

test('/register spam is rate limited', async () => {
  const codes = [];
  for (let i = 0; i < 10; i++) {
    const r = await register(uniqEmail('rl'));
    codes.push(r.status);
  }
  assert.ok(codes.includes(429), `expected a 429 within 10 rapid registrations, got ${JSON.stringify(codes)}`);
});

// ── Registration privilege escalation / mass assignment ──
test('register cannot self-grant super_admin', async () => {
  const r = await register(uniqEmail('esc'), { permission: 'super_admin' });
  assert.equal(r.status, 403, 'super_admin escalation must be rejected');
});

test('register ignores mass-assigned is_active/shop_id/permissions', async () => {
  const email = uniqEmail('ma');
  await register(email, { is_active: false, shop_id: 1, permissions: ['super_admin'] });
  const token = await login(email);
  assert.ok(token, 'user should be active (is_active:false must be ignored)');
  const me = await (await fetch(`${BASE}/me`, { headers: { ...H, Authorization: `Bearer ${token}` } })).json();
  assert.equal(me.shop_id, null, 'shop_id must not be mass-assignable');
});

// ── User profile update IDOR + mass assignment ──
test('customer cannot escalate own account via profile update', async () => {
  const a = await makeCustomer('upd');
  const me = await (await fetch(`${BASE}/me`, { headers: { Authorization: `Bearer ${a.token}` } })).json();
  await fetch(`${BASE}/users/${me.id}`, {
    method: 'PUT', headers: { ...H, Authorization: `Bearer ${a.token}` },
    body: j({ name: 'X', is_active: false, id: 999999, shop_id: 1, permissions: ['super_admin'] }),
  });
  const after = await (await fetch(`${BASE}/me`, { headers: { Authorization: `Bearer ${a.token}` } })).json();
  assert.equal(after.id, me.id, 'id must not change');
  assert.equal(after.is_active, 1, 'is_active must not be self-downgradable/settable');
  assert.equal(after.shop_id, null, 'shop_id must not be self-assignable');
});

// ── Order IDOR ──
test('unauthenticated order fetch by id/tracking returns 404 (no enumeration)', async () => {
  for (const p of ['1', '5', '149', '20260620156919']) {
    const r = await fetch(`${BASE}/orders/${p}`, { headers: H });
    assert.equal(r.status, 404, `unauth order ${p} must 404`);
  }
});

test('customer cannot read another customer order (cross-user IDOR)', async () => {
  // 149 is a known registered order owned by customer 8 on staging.
  const b = await makeCustomer('idor');
  const r = await fetch(`${BASE}/orders/149`, { headers: { Authorization: `Bearer ${b.token}` } });
  assert.equal(r.status, 404, 'non-owner must not see order');
});

test('findByTrackingNumber requires auth and hides non-owned orders', async () => {
  const anon = await fetch(`${BASE}/orders/tracking-number/20260620156919`, { headers: H });
  assert.equal(anon.status, 401, 'route must require auth');
  const b = await makeCustomer('tn');
  const r = await fetch(`${BASE}/orders/tracking-number/20260620156919`, { headers: { Authorization: `Bearer ${b.token}` } });
  const body = await r.text();
  assert.ok(/NOT_FOUND/.test(body), 'non-owner must get NOT_FOUND, not order data');
});

test('guest order token cannot be bypassed with a guessed token', async () => {
  const r = await fetch(`${BASE}/orders/20260620156919?token=12345`, { headers: H });
  assert.equal(r.status, 404, 'wrong tracking_token must 404 (constant-time compare)');
});

// ── Bulk export authorization ──
test('bulk product export requires authorization', async () => {
  // Regression guard: export-products/{shop_id} must NOT be reachable unauthenticated.
  const r = await fetch(`${BASE}/export-products/12`, { headers: H });
  assert.ok([401, 403].includes(r.status), `unauth export must be 401/403, got ${r.status} (currently reachable => broken access control + DoS)`);
});

// ── Injection ──
test('search value is parameterized (no boolean/timing SQLi)', async () => {
  const r = await fetch(`${BASE}/products?search=name:' OR 1=1--&limit=1`, { headers: H });
  assert.equal(r.status, 200);
  const d = await r.json();
  assert.ok(Array.isArray(d.data) && d.data.length === 0, 'injection payload must not return rows');
});

test('blind SLEEP injection via orderBy is not executed', async () => {
  const t0 = Date.now();
  await fetch(`${BASE}/products?orderBy=id,(SELECT SLEEP(5))&limit=1`, { headers: H });
  assert.ok(Date.now() - t0 < 3000, 'orderBy must not execute SLEEP() (no time-based SQLi)');
});

// ── Error / debug leakage ──
test('no APP_DEBUG stack traces or framework internals leak', async () => {
  const bodies = await Promise.all([
    fetch(`${BASE}/token`, { method: 'POST', headers: H, body: '{bad json' }).then((r) => r.text()),
    fetch(`${BASE}/token`, { method: 'POST', headers: H, body: j({ email: { x: 1 }, password: ['a'] }) }).then((r) => r.text()),
    fetch(`${BASE}/download-invoice/token/abc123`, { headers: H }).then((r) => r.text()),
  ]);
  for (const b of bodies) {
    assert.ok(!/#0 |\/var\/www|vendor\/laravel|Stack trace|SQLSTATE/i.test(b), `stack/path/SQL leak: ${b.slice(0, 200)}`);
    // Model class namespace disclosure (currently leaks on download-invoice).
    assert.ok(!/Marvel\\Database\\Models\\/.test(b), `internal model class leaked: ${b.slice(0, 200)}`);
  }
});

// ── CORS ──
test('CORS does not reflect arbitrary origins with credentials', async () => {
  const r = await fetch(`${BASE}/settings`, { headers: { Origin: 'https://evil.example.com', Accept: 'application/json' } });
  const acao = r.headers.get('access-control-allow-origin');
  const creds = r.headers.get('access-control-allow-credentials');
  assert.ok(!(acao === 'https://evil.example.com' && creds === 'true'), 'must not reflect evil origin with credentials');
});
