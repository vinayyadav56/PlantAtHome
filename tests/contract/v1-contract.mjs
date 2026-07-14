// V2 platform API contract suite — STAGING only.
// Asserts standard envelope {success,data,meta,errors}, status codes, 422 error
// shape {code,field,message}, pagination meta, and public/optional/jwt gating.
//
// Run: V1_BASE=https://plantathome-production.up.railway.app/api/v1 node v1-contract.mjs
const BASE = process.env.V1_BASE || 'https://plantathome-production.up.railway.app/api/v1';
const ADMIN = { email: process.env.V1_ADMIN_EMAIL || 'admin@plantathome.test', password: process.env.V1_ADMIN_PASSWORD || 'Passw0rd!' };

let pass = 0, fail = 0;
const fails = [];
function ok(cond, msg, detail) {
  if (cond) { pass++; }
  else { fail++; fails.push({ msg, detail }); console.log('  FAIL:', msg, detail ? JSON.stringify(detail).slice(0, 300) : ''); }
}

async function req(method, path, { token, body, headers = {} } = {}) {
  const h = { 'Accept': 'application/json', ...headers };
  if (token) h['Authorization'] = `Bearer ${token}`;
  if (body !== undefined) h['Content-Type'] = 'application/json';
  const res = await fetch(BASE + path, { method, headers: h, body: body !== undefined ? JSON.stringify(body) : undefined });
  let json = null; const text = await res.text();
  try { json = JSON.parse(text); } catch {}
  return { status: res.status, json, text, headers: res.headers };
}

function assertEnvelope(label, r, expectStatus) {
  const j = r.json;
  ok(r.status === expectStatus, `${label}: status ${expectStatus}`, { got: r.status, body: r.text?.slice(0,200) });
  ok(j && typeof j === 'object', `${label}: JSON body`, { text: r.text?.slice(0,120) });
  if (!j || typeof j !== 'object') return;
  ok(typeof j.success === 'boolean', `${label}: success is boolean`, { success: j.success });
  ok('data' in j, `${label}: has data key`);
  ok('meta' in j && j.meta !== null && typeof j.meta === 'object' && !Array.isArray(j.meta), `${label}: meta is object`, { meta: j.meta });
  ok(Array.isArray(j.errors), `${label}: errors is array`, { errors: j.errors });
  const expectSuccess = expectStatus < 400;
  ok(j.success === expectSuccess, `${label}: success matches status`, { success: j.success, status: r.status });
  if (!expectSuccess) {
    ok(j.errors.length >= 1, `${label}: error body has >=1 error`, { errors: j.errors });
    for (const e of j.errors || []) {
      ok(e && typeof e.code === 'string' && e.code.length > 0, `${label}: error has code`, e);
      ok(e && typeof e.message === 'string' && e.message.length > 0, `${label}: error has message`, e);
    }
  } else {
    ok(j.errors.length === 0, `${label}: success has empty errors`, { errors: j.errors });
  }
  return j;
}

function assertPagination(label, j) {
  const p = j?.meta?.pagination;
  ok(p && typeof p === 'object', `${label}: meta.pagination present`, { meta: j?.meta });
  if (p) for (const k of ['total','per_page','current_page','last_page']) ok(typeof p[k] === 'number', `${label}: pagination.${k} numeric`, { p });
}

(async () => {
  console.log('BASE =', BASE, '\n');

  console.log('# Platform');
  let r = await req('GET', '/health');
  const health = assertEnvelope('GET /health', r, 200);
  ok(health?.data?.status === 'ok', 'health.data.status ok', health?.data);
  ok(health?.data?.db === 'connected', 'health.data.db connected', health?.data);
  r = await req('GET', '/platform/status');
  assertEnvelope('GET /platform/status (noauth)', r, 401);

  console.log('# Identity');
  r = await req('POST', '/auth/login', { body: ADMIN });
  const login = assertEnvelope('POST /auth/login', r, 200);
  const token = login?.data?.tokens?.access_token;
  ok(typeof token === 'string' && token.length > 20, 'login returns access_token', { has: !!token });
  ok(login?.data?.user?.role === 'admin', 'login user role admin', login?.data?.user?.role);
  r = await req('POST', '/auth/login', { body: { email: ADMIN.email, password: 'wrong-pass-xyz' } });
  assertEnvelope('POST /auth/login (bad creds)', r, 401);
  r = await req('POST', '/auth/login', { body: { email: 'not-an-email' } });
  const v = assertEnvelope('POST /auth/login (invalid)', r, 422);
  ok((v?.errors || []).every(e => e.code === 'VALIDATION_ERROR' && typeof e.field === 'string'), '422 errors carry VALIDATION_ERROR code + field', v?.errors);
  r = await req('GET', '/auth/me', { token });
  assertEnvelope('GET /auth/me', r, 200);
  r = await req('GET', '/auth/me');
  assertEnvelope('GET /auth/me (noauth)', r, 401);
  r = await req('POST', '/auth/refresh', { body: { refresh_token: 'invalid' } });
  assertEnvelope('POST /auth/refresh (invalid)', r, 401);
  r = await req('GET', '/admin/ping', { token });
  assertEnvelope('GET /admin/ping (admin)', r, 200);
  r = await req('GET', '/admin/ping');
  assertEnvelope('GET /admin/ping (noauth)', r, 401);
  r = await req('GET', '/platform/status', { token });
  assertEnvelope('GET /platform/status (admin)', r, 200);

  console.log('# Catalog');
  r = await req('GET', '/catalog/products');
  const prods = assertEnvelope('GET /catalog/products (guest)', r, 200);
  ok(Array.isArray(prods?.data), 'products.data is array', typeof prods?.data);
  assertPagination('GET /catalog/products', prods);
  ok((prods?.data || []).every(p => p.status === 'published'), 'guest sees only published (no draft leak)', (prods?.data||[]).map(p=>p.status));
  const productId = prods?.data?.[0]?.uuid || prods?.data?.[0]?.id;
  r = await req('GET', '/catalog/categories');
  assertEnvelope('GET /catalog/categories (guest)', r, 200);
  r = await req('GET', '/catalog/attributes');
  assertEnvelope('GET /catalog/attributes (guest)', r, 200);
  r = await req('GET', '/catalog/products/00000000-0000-0000-0000-000000000000');
  assertEnvelope('GET /catalog/products/{unknown}', r, 404);
  r = await req('POST', '/catalog/products', { body: { name: 'x' } });
  assertEnvelope('POST /catalog/products (noauth)', r, 401);
  r = await req('POST', '/catalog/products', { token, body: {} });
  ok(r.status === 422, 'POST /catalog/products (admin, empty) => 422', { got: r.status });
  assertEnvelope('POST /catalog/products (admin,empty)', r, r.status);

  console.log('# Configuration');
  r = await req('GET', '/config/groups', { token });
  assertEnvelope('GET /config/groups (admin)', r, 200);
  r = await req('GET', '/config/groups');
  assertEnvelope('GET /config/groups (noauth)', r, 401);
  if (productId) {
    r = await req('GET', `/config/products/${productId}/configuration`);
    ok([200,404,422].includes(r.status), 'GET config resolution status ok (422 VARIANT_REQUIRED valid)', { got: r.status });
    assertEnvelope('GET /config/products/{id}/configuration', r, r.status);
  }

  console.log('# Rules');
  r = await req('GET', '/rules', { token });
  assertEnvelope('GET /rules (admin)', r, 200);
  r = await req('GET', '/rules');
  assertEnvelope('GET /rules (noauth)', r, 401);

  console.log('# Inventory');
  r = await req('GET', '/inventory/availability');
  ok([200,422].includes(r.status), 'GET /inventory/availability status', { got: r.status });
  assertEnvelope('GET /inventory/availability', r, r.status);
  r = await req('PUT', '/inventory/stock', { body: {} });
  assertEnvelope('PUT /inventory/stock (noauth)', r, 401);

  console.log('# Pricing');
  r = await req('POST', '/pricing/quote', { body: {} });
  ok([200,422].includes(r.status), 'POST /pricing/quote (empty) status', { got: r.status });
  assertEnvelope('POST /pricing/quote (empty)', r, r.status);
  r = await req('POST', '/pricing/base-prices', { body: {} });
  assertEnvelope('POST /pricing/base-prices (noauth)', r, 401);

  console.log('# Serviceability');
  r = await req('GET', '/serviceability/cities');
  assertEnvelope('GET /serviceability/cities (public)', r, 200);
  r = await req('POST', '/serviceability/delivery-check', { body: {} });
  ok([200,422].includes(r.status), 'POST /serviceability/delivery-check status', { got: r.status });
  assertEnvelope('POST /serviceability/delivery-check (empty)', r, r.status);
  r = await req('POST', '/serviceability/cities', { body: {} });
  assertEnvelope('POST /serviceability/cities (noauth)', r, 401);

  console.log('# Sales');
  r = await req('GET', '/cart');
  assertEnvelope('GET /cart (noauth)', r, 401);
  r = await req('GET', '/cart', { token });
  ok([200,403].includes(r.status), 'GET /cart auth status ok', { got: r.status });
  assertEnvelope('GET /cart (admin token)', r, r.status);

  console.log('# Search');
  r = await req('GET', '/search?q=plant');
  ok([200,422].includes(r.status), 'GET /search status', { got: r.status });
  assertEnvelope('GET /search?q=plant', r, r.status);
  r = await req('GET', '/search/autocomplete?q=pl');
  ok([200,422].includes(r.status), 'GET /search/autocomplete status', { got: r.status });
  assertEnvelope('GET /search/autocomplete', r, r.status);

  console.log('# Promotions');
  r = await req('POST', '/promotions/validate', { body: {} });
  ok([200,422].includes(r.status), 'POST /promotions/validate status', { got: r.status });
  assertEnvelope('POST /promotions/validate (empty)', r, r.status);
  r = await req('GET', '/promotions/coupons', { token });
  assertEnvelope('GET /promotions/coupons (admin)', r, 200);
  r = await req('GET', '/promotions/coupons');
  assertEnvelope('GET /promotions/coupons (noauth)', r, 401);

  console.log('# Notifications');
  r = await req('GET', '/notifications/log', { token });
  assertEnvelope('GET /notifications/log (admin)', r, 200);
  r = await req('GET', '/notifications/log');
  assertEnvelope('GET /notifications/log (noauth)', r, 401);

  console.log('# CMS');
  r = await req('GET', '/cms/banners');
  assertEnvelope('GET /cms/banners (public)', r, 200);
  r = await req('GET', '/cms/pages/nonexistent-slug-xyz');
  ok([200,404].includes(r.status), 'GET /cms/pages/{slug} status', { got: r.status });
  assertEnvelope('GET /cms/pages/{unknown}', r, r.status);
  r = await req('POST', '/cms/pages', { body: {} });
  assertEnvelope('POST /cms/pages (noauth)', r, 401);

  console.log('# Analytics');
  r = await req('GET', '/analytics/kpis', { token });
  assertEnvelope('GET /analytics/kpis (admin)', r, 200);
  r = await req('GET', '/analytics/kpis');
  assertEnvelope('GET /analytics/kpis (noauth)', r, 401);

  console.log('# Cross-cutting');
  r = await req('GET', '/this-route-does-not-exist-xyz');
  assertEnvelope('GET /unknown-route', r, 404);
  r = await req('DELETE', '/health');
  ok([404,405].includes(r.status), 'DELETE /health => 404/405', { got: r.status });
  if (r.status === 405) { assertEnvelope('DELETE /health (405)', r, 405); ok(!!r.headers.get('allow'), '405 carries Allow header', r.headers.get('allow')); }
  r = await req('GET', '/auth/me', { token: 'garbage.token.value' });
  assertEnvelope('GET /auth/me (bad token)', r, 401);

  console.log(`\n==== ${pass} passed, ${fail} failed ====`);
  if (fail) { console.log('\nFAILURES:'); fails.forEach(f => console.log(' -', f.msg, f.detail ? JSON.stringify(f.detail).slice(0,200): '')); }
  process.exit(fail ? 1 : 0);
})();
