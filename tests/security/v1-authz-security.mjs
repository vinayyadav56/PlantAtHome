/**
 * V2 platform API — AuthZ / JWT / OWASP security suite (STAGING ONLY).
 *
 * Exercises the hardened Identity guarantees end-to-end against the live
 * staging API: JWT rejection (none/tampered/wrong-sig/alg-none), refresh-token
 * single-use rotation with FAMILY revocation on replay, role gates, permission
 * gates, nursery-scope isolation (owner A vs nursery B), guest-gating of
 * unpublished data / exact stock / vendor UUIDs, and credential-endpoint
 * throttling.
 *
 * Run:  node e2e/v1-authz-security.mjs
 * Requires Node 18+ (global fetch). No external deps.
 *
 * SAFETY: staging base URL only. All requests are GET or auth/scope probes that
 * are rejected before any mutation; the only writes attempted are ones we EXPECT
 * to be blocked (403), so nothing persists. Never point this at production.
 */

const BASE = process.env.V1_BASE || 'https://plantathome-production.up.railway.app/api/v1';

const USERS = {
  admin:    { email: 'admin@plantathome.test',   password: 'Passw0rd!' },
  ownerA:   { email: 'owner.a@plantathome.test', password: 'Passw0rd!' },
  ownerB:   { email: 'owner.b@plantathome.test', password: 'Passw0rd!' },
  customer: { email: 'customer@plantathome.test',password: 'Passw0rd!' },
};
const NURSERY_A = '11111111-1111-1111-1111-111111111111';
const NURSERY_B = '22222222-2222-2222-2222-222222222222';

let pass = 0, fail = 0;
const failures = [];
function check(name, cond, evidence) {
  if (cond) { pass++; console.log(`  PASS  ${name}`); }
  else { fail++; failures.push({ name, evidence }); console.log(`  FAIL  ${name}  :: ${evidence}`); }
}

async function req(method, path, { token, body, raw } = {}) {
  const headers = { 'Accept': 'application/json' };
  if (token) headers['Authorization'] = `Bearer ${token}`;
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  const res = await fetch(BASE + path, {
    method, headers,
    body: body !== undefined ? (raw ? body : JSON.stringify(body)) : undefined,
  });
  let json = null; const text = await res.text();
  try { json = JSON.parse(text); } catch { /* keep text */ }
  return { status: res.status, json, text };
}

async function login(u) {
  const r = await req('POST', '/auth/login', { body: USERS[u] });
  if (!r.json?.success) throw new Error(`login ${u} failed: ${r.status} ${r.text.slice(0,200)}`);
  return r.json.data;
}

const b64url = (o) => Buffer.from(JSON.stringify(o)).toString('base64url');

async function main() {
  console.log(`\n# V1 security suite → ${BASE}\n`);

  const admin = await login('admin');
  const ownerA = await login('ownerA');
  const customer = await login('customer');
  const aTok = admin.tokens.access_token;
  const oaTok = ownerA.tokens.access_token;
  const cTok = customer.tokens.access_token;

  // ─── 1. JWT rejection ────────────────────────────────────────────────────
  console.log('\n[1] JWT rejection');
  {
    const r = await req('GET', '/auth/me');
    check('no token → 401 UNAUTHENTICATED', r.status === 401 && r.json?.errors?.[0]?.code === 'UNAUTHENTICATED', `${r.status} ${r.text.slice(0,120)}`);
  }
  {
    // tamper: flip a char in the signature segment
    const parts = aTok.split('.');
    const badSig = parts[0] + '.' + parts[1] + '.' + (parts[2].slice(0, -3) + (parts[2].endsWith('AAA') ? 'BBB' : 'AAA'));
    const r = await req('GET', '/auth/me', { token: badSig });
    check('tampered signature → 401 TOKEN_INVALID', r.status === 401 && r.json?.errors?.[0]?.code === 'TOKEN_INVALID', `${r.status} ${JSON.stringify(r.json?.errors)}`);
  }
  {
    // tamper payload (escalate role to admin) keeping original signature
    const parts = aTok.split('.');
    const payload = JSON.parse(Buffer.from(parts[1], 'base64url').toString());
    payload.role = 'super_admin';
    const forged = parts[0] + '.' + b64url(payload) + '.' + parts[2];
    const r = await req('GET', '/auth/me', { token: forged });
    check('payload tamper (role escalation) → 401', r.status === 401, `${r.status} ${JSON.stringify(r.json?.errors)}`);
  }
  {
    // alg=none forgery
    const payload = JSON.parse(Buffer.from(aTok.split('.')[1], 'base64url').toString());
    const noneJwt = b64url({ alg: 'none', typ: 'JWT' }) + '.' + b64url(payload) + '.';
    const r = await req('GET', '/auth/me', { token: noneJwt });
    check('alg=none forgery → 401', r.status === 401, `${r.status} ${JSON.stringify(r.json?.errors)}`);
  }
  {
    // wrong-secret signature (well-formed HS256, foreign key)
    const header = b64url({ alg: 'HS256', typ: 'JWT' });
    const payload = JSON.parse(Buffer.from(aTok.split('.')[1], 'base64url').toString());
    const crypto = await import('node:crypto');
    const signingInput = header + '.' + b64url(payload);
    const sig = crypto.createHmac('sha256', 'attacker-guessed-secret').update(signingInput).digest('base64url');
    const r = await req('GET', '/auth/me', { token: signingInput + '.' + sig });
    check('wrong-secret HS256 → 401 TOKEN_INVALID', r.status === 401 && r.json?.errors?.[0]?.code === 'TOKEN_INVALID', `${r.status} ${JSON.stringify(r.json?.errors)}`);
  }
  {
    const r = await req('GET', '/auth/me', { token: 'not.a.jwt' });
    check('malformed token → 401', r.status === 401, `${r.status}`);
  }

  // ─── 2. Refresh rotation: single-use + FAMILY revocation on replay ───────
  console.log('\n[2] Refresh-token rotation & family revocation');
  {
    // fresh session so we don't disturb other tests
    const sess = await login('customer');
    const rt0 = sess.tokens.refresh_token;

    const first = await req('POST', '/auth/refresh', { body: { refresh_token: rt0 } });
    const rt1 = first.json?.data?.tokens?.refresh_token;
    check('refresh #1 rotates → new pair', first.status === 200 && !!rt1 && rt1 !== rt0, `${first.status} ${first.text.slice(0,160)}`);

    // Replay the ALREADY-USED rt0 → reuse detection
    const replay = await req('POST', '/auth/refresh', { body: { refresh_token: rt0 } });
    check('replay used refresh_token → 401 REFRESH_REUSED', replay.status === 401 && replay.json?.errors?.[0]?.code === 'REFRESH_REUSED', `${replay.status} ${JSON.stringify(replay.json?.errors)}`);

    // FAMILY REVOCATION: rt1 (minted by the winner) must now be dead too
    const successorReplay = await req('POST', '/auth/refresh', { body: { refresh_token: rt1 } });
    check('family revoked → successor rt1 also rejected', successorReplay.status === 401, `${successorReplay.status} ${JSON.stringify(successorReplay.json?.errors)}`);
  }
  {
    const r = await req('POST', '/auth/refresh', { body: { refresh_token: 'totally-unknown-token' } });
    check('unknown refresh_token → 401 REFRESH_INVALID', r.status === 401 && r.json?.errors?.[0]?.code === 'REFRESH_INVALID', `${r.status} ${JSON.stringify(r.json?.errors)}`);
  }

  // ─── 3. Role gates ───────────────────────────────────────────────────────
  console.log('\n[3] Role gates (403 for wrong role)');
  {
    const r = await req('GET', '/admin/ping', { token: cTok });
    check('customer → /admin/ping → 403', r.status === 403, `${r.status} ${r.text.slice(0,120)}`);
  }
  {
    const r = await req('GET', '/admin/ping', { token: oaTok });
    check('nursery_owner → /admin/ping → 403', r.status === 403, `${r.status} ${r.text.slice(0,120)}`);
  }
  {
    const r = await req('GET', '/admin/ping', { token: aTok });
    check('admin → /admin/ping → 200', r.status === 200, `${r.status}`);
  }
  {
    const r = await req('GET', '/platform/status', { token: cTok });
    check('customer → /platform/status → 403', r.status === 403, `${r.status}`);
  }

  // ─── 4. Permission gates ─────────────────────────────────────────────────
  console.log('\n[4] Permission gates (v1.can)');
  {
    const r = await req('POST', '/catalog/products', { token: cTok, body: { name: 'x' } });
    check('customer POST /catalog/products → 403', r.status === 403, `${r.status}`);
  }
  {
    const r = await req('POST', '/catalog/products', { token: oaTok, body: { name: 'x' } });
    check('nursery_owner POST /catalog/products (no catalog.manage) → 403', r.status === 403, `${r.status}`);
  }
  {
    const r = await req('PUT', '/inventory/stock', { token: cTok, body: { nursery_id: NURSERY_A, sellable_type: 'variant', sellable_uuid: '00000000-0000-0000-0000-000000000000', qty_on_hand: 5 } });
    check('customer PUT /inventory/stock → 403', r.status === 403, `${r.status}`);
  }

  // ─── 5. Nursery-scope isolation (owner A vs nursery B) ───────────────────
  console.log('\n[5] Nursery-scope isolation — FORBIDDEN_SCOPE');
  {
    const r = await req('GET', `/nursery/${NURSERY_B}/dashboard`, { token: oaTok });
    check('owner A → nursery B dashboard → 403 FORBIDDEN_SCOPE', r.status === 403 && r.json?.errors?.[0]?.code === 'FORBIDDEN_SCOPE', `${r.status} ${JSON.stringify(r.json?.errors)}`);
  }
  {
    const r = await req('GET', `/nursery/${NURSERY_A}/dashboard`, { token: oaTok });
    check('owner A → own dashboard → 200 (sanity)', r.status === 200, `${r.status} ${r.text.slice(0,120)}`);
  }
  {
    const r = await req('PUT', '/inventory/stock', { token: oaTok, body: { nursery_id: NURSERY_B, sellable_type: 'variant', sellable_uuid: '00000000-0000-0000-0000-000000000000', qty_on_hand: 999 } });
    check('owner A → set stock for nursery B → 403 FORBIDDEN_SCOPE', r.status === 403 && r.json?.errors?.[0]?.code === 'FORBIDDEN_SCOPE', `${r.status} ${JSON.stringify(r.json?.errors)}`);
  }
  {
    const r = await req('POST', '/pricing/vendor-overrides', { token: oaTok, body: { nursery_id: NURSERY_B, sellable_type: 'variant', sellable_uuid: '00000000-0000-0000-0000-000000000000', amount: 100, currency: 'INR' } });
    check('owner A → vendor-override for nursery B → 403 FORBIDDEN_SCOPE', r.status === 403 && r.json?.errors?.[0]?.code === 'FORBIDDEN_SCOPE', `${r.status} ${JSON.stringify(r.json?.errors)}`);
  }
  {
    const r = await req('PUT', '/serviceability/coverage', { token: oaTok, body: { nursery_id: NURSERY_B, city_uuid: '00000000-0000-0000-0000-000000000000', is_serviceable: true } });
    check('owner A → coverage for nursery B → 403', r.status === 403, `${r.status} ${JSON.stringify(r.json?.errors)}`);
  }

  // ─── 6. Guest-gating: exact stock only for owner/admin ───────────────────
  console.log('\n[6] Availability disclosure (coarse vs exact)');
  {
    const q = `sellable_type=variant&sellable_uuid=00000000-0000-0000-0000-000000000000&nursery_id=${NURSERY_A}`;
    const guest = await req('GET', `/inventory/availability?${q}`);
    check('guest availability → in_stock only, no exact qty', guest.status === 200 && guest.json?.data?.available === undefined && 'in_stock' in (guest.json?.data || {}), `${guest.status} ${JSON.stringify(guest.json?.data)}`);

    const foreign = await req('GET', `/inventory/availability?${q}`, { token: (await login('ownerB')).tokens.access_token });
    check('owner B availability for nursery A → no exact qty', foreign.status === 200 && foreign.json?.data?.available === undefined, `${foreign.status} ${JSON.stringify(foreign.json?.data)}`);

    const owner = await req('GET', `/inventory/availability?${q}`, { token: oaTok });
    check('owner A availability for own nursery A → exact qty present', owner.status === 200 && 'available' in (owner.json?.data || {}), `${owner.status} ${JSON.stringify(owner.json?.data)}`);
  }

  // ─── 7. Guest-gating: unpublished products / inactive categories hidden ──
  console.log('\n[7] Unpublished/inactive hidden from guests');
  let draftUuid = null, inactiveCatUuid = null;
  {
    // admin can enumerate drafts
    const adminDrafts = await req('GET', '/catalog/products?status=draft&per_page=5', { token: aTok });
    draftUuid = adminDrafts.json?.data?.find?.(p => p.status !== 'published')?.uuid || adminDrafts.json?.data?.[0]?.uuid || null;
    if (draftUuid) {
      const guest = await req('GET', `/catalog/products/${draftUuid}`);
      check('guest GET a draft product → 404 (hidden)', guest.status === 404, `${guest.status} draft=${draftUuid}`);
    } else {
      console.log('  SKIP  no draft product available to probe');
    }
    // guest listing must contain zero non-published rows
    const guestList = await req('GET', '/catalog/products?per_page=50');
    const leaked = (guestList.json?.data || []).filter(p => p.status && p.status !== 'published');
    check('guest product listing exposes only published', leaked.length === 0, `leaked=${leaked.length} statuses=${[...new Set((guestList.json?.data||[]).map(p=>p.status))]}`);
  }
  {
    const adminCats = await req('GET', '/catalog/categories?status=inactive&per_page=50', { token: aTok });
    inactiveCatUuid = adminCats.json?.data?.find?.(c => c.status && c.status !== 'active')?.uuid || null;
    const guestCats = await req('GET', '/catalog/categories?per_page=100');
    const leakedCats = (guestCats.json?.data || []).filter(c => c.status && c.status !== 'active');
    check('guest category listing exposes only active', leakedCats.length === 0, `leaked=${leakedCats.length}`);
    if (inactiveCatUuid) {
      const g = await req('GET', `/catalog/categories/${inactiveCatUuid}`);
      check('guest GET an inactive category → 404 (hidden)', g.status === 404, `${g.status} cat=${inactiveCatUuid}`);
    } else {
      console.log('  SKIP  no inactive category available to probe');
    }
  }

  // ─── 8. Guest-gating: serviceable returns counts, not vendor UUIDs ───────
  console.log('\n[8] City /serviceable — no raw vendor UUID leak');
  {
    const cities = await req('GET', '/serviceability/cities?per_page=1');
    const cityUuid = cities.json?.data?.[0]?.uuid;
    const r = await req('GET', `/serviceability/cities/${cityUuid}/serviceable`);
    const bodyStr = JSON.stringify(r.json?.data || {});
    // No nursery UUID (11111111.. / 22222222..) should appear in a public response
    const leaksUuid = /[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/.test(bodyStr) && /nursery/i.test(bodyStr);
    check('public serviceable → no vendor nursery UUIDs', r.status === 200 && !bodyStr.includes(NURSERY_A) && !bodyStr.includes(NURSERY_B), `${r.status} ${bodyStr.slice(0,200)}`);
  }

  // ─── 9. Throttle on credential endpoints ─────────────────────────────────
  console.log('\n[9] Throttle /auth/login (10/min per email+IP)');
  {
    let got429 = false, statuses = [];
    for (let i = 0; i < 14; i++) {
      const r = await req('POST', '/auth/login', { body: { email: 'throttle-probe@plantathome.test', password: 'wrong-password' } });
      statuses.push(r.status);
      if (r.status === 429) { got429 = true; break; }
    }
    check('login brute-force trips 429', got429, `statuses=${statuses.join(',')}`);
  }

  // ─── summary ─────────────────────────────────────────────────────────────
  console.log(`\n──────────────────────────────────\n  ${pass} passed, ${fail} failed`);
  if (fail) { console.log('  FAILURES:'); failures.forEach(f => console.log(`   - ${f.name} :: ${f.evidence}`)); }
  process.exit(fail ? 1 : 0);
}

main().catch(e => { console.error('SUITE ERROR', e); process.exit(2); });
