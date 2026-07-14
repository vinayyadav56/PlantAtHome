// V2 money-path correctness + concurrency suite — STAGING ONLY.
// Proves: (1) tamper-proof pricing, (2) no-oversell under parallel reservations,
// (3) idempotent pay (double + 8-concurrent -> 1 order), (4) refund restocks once
// & double-refund blocked, (5) coupon usage_limit enforced under concurrency.
//
// Run:  node money-path.mjs
// Requires Node 18+ (global fetch). No deps. STAGING /api/v1 only (mutating).

const BASE = process.env.V1_BASE || 'https://plantathome-production.up.railway.app/api/v1';
const NURSERY = '11111111-1111-1111-1111-111111111111'; // owner.a@plantathome.test
const PW = 'Passw0rd!';
const rnd = () => Math.random().toString(36).slice(2, 10);

let PASS = 0, FAIL = 0;
function check(name, cond, detail = '') {
  (cond ? PASS++ : FAIL++);
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`);
}

async function api(method, path, { token, body, headers = {} } = {}) {
  const h = { 'Content-Type': 'application/json', ...headers };
  if (token) h.Authorization = `Bearer ${token}`;
  const res = await fetch(BASE + path, { method, headers: h, body: body ? JSON.stringify(body) : undefined });
  let json = null;
  try { json = await res.json(); } catch { /* non-json */ }
  return { status: res.status, json };
}

async function login(email) {
  const { json } = await api('POST', '/auth/login', { body: { email, password: PW } });
  if (!json?.data?.tokens?.access_token) throw new Error('login failed for ' + email);
  return { token: json.data.tokens.access_token, user: json.data.user };
}

async function main() {
  const admin = await login('admin@plantathome.test');
  const customer = await login('customer@plantathome.test');
  const A = admin.token, C = customer.token;

  // ── Fixture: dedicated product + variant, priced + stocked ──────────────
  const mk = await api('POST', '/catalog/products', {
    token: A,
    body: { name: 'MONEY E2E ' + rnd(), status: 'published',
      variants: [{ sku: 'money-e2e-' + rnd(), size_code: 'S', name: 'Small' }] },
  });
  const product = mk.json.data;
  const variant = product.variants[0].uuid;
  const UNIT = 500; // ₹500 base price
  await api('POST', '/pricing/base-prices', { token: A,
    body: { sellable_type: 'variant', sellable_uuid: variant, amount: UNIT, currency: 'INR' } });

  const setStock = (qty) => api('PUT', '/inventory/stock', { token: A,
    body: { sellable_type: 'variant', sellable_uuid: variant, nursery_id: NURSERY, qty_on_hand: qty, track: true } });
  const availability = async () => (await api('GET',
    `/inventory/availability?sellable_type=variant&sellable_uuid=${variant}&nursery_id=${NURSERY}`,
    { token: A })).json.data.available;

  // ════ TEST 1: PRICING is tamper-proof ════════════════════════════════════
  {
    // Inject bogus client prices; server must ignore them and re-derive from base.
    const { status, json } = await api('POST', '/pricing/quote', { token: C, body: {
      variant_uuid: variant, nursery_id: NURSERY, qty: 2,
      price: 1, amount: 1, amount_minor: 1, total: 1, subtotal: 1, // <- tamper attempt
    }});
    const total = json?.data?.total?.amount_minor;
    check('T1 quote ignores client-sent price (re-derives server-side)',
      status === 200 && total === UNIT * 100 * 2, `total_minor=${total} expected=${UNIT * 100 * 2}`);

    // Determinism: same identifiers -> same authoritative total.
    const q2 = await api('POST', '/pricing/quote', { token: C, body: { variant_uuid: variant, nursery_id: NURSERY, qty: 2 } });
    check('T1 quote is deterministic', q2.json?.data?.total?.amount_minor === total,
      `${q2.json?.data?.total?.amount_minor} vs ${total}`);

    // Non-INR currency -> clean 422 (not a 500), no INR mixing.
    const nonInr = await api('POST', '/pricing/quote', { token: C, body: { variant_uuid: variant, nursery_id: NURSERY, currency: 'USD' } });
    check('T1 non-INR currency -> 422 UNSUPPORTED_CURRENCY',
      nonInr.status === 422 && nonInr.json?.errors?.[0]?.code === 'UNSUPPORTED_CURRENCY',
      `status=${nonInr.status} code=${nonInr.json?.errors?.[0]?.code}`);
  }

  // ════ TEST 2: NO-OVERSELL under parallel reservations ════════════════════
  {
    const STOCK = 5, ATTEMPTS = 20;
    await setStock(STOCK);
    // Fire ATTEMPTS single-unit reservations concurrently, each its own session.
    const sessions = Array.from({ length: ATTEMPTS }, () => crypto.randomUUID());
    const res = await Promise.all(sessions.map((sid) => api('POST', '/inventory/reservations', { token: A, body: {
      checkout_session_id: sid, ttl_seconds: 120,
      items: [{ sellable_type: 'variant', sellable_uuid: variant, nursery_id: NURSERY, qty: 1 }],
    }})));
    const ok = res.filter((r) => r.status === 201);
    const conflict = res.filter((r) => r.status === 409);
    const reservedTotal = ok.reduce((n, r) => n + (r.json?.data?.reserved || 0), 0);
    check('T2 successful reservations never exceed stock',
      ok.length === STOCK && reservedTotal === STOCK,
      `granted=${ok.length} conflicts=${conflict.length} reservedTotal=${reservedTotal} stock=${STOCK}`);
    check('T2 over-cap reservations rejected with 409 INSUFFICIENT_STOCK',
      conflict.length === ATTEMPTS - STOCK && conflict.every((r) => r.json?.errors?.[0]?.code === 'INSUFFICIENT_STOCK'),
      `conflicts=${conflict.length}`);
    check('T2 availability driven to exactly 0 (no negative / no leak)',
      (await availability()) === 0, `available=${await availability()}`);
    // cleanup: release the held reservations
    await Promise.all(ok.map((_, i) => api('POST', `/inventory/reservations/${sessions[i]}/release`, { token: A })));
  }

  // ── helper: full customer flow -> starts a pending checkout on a fresh line
  async function buildCheckout({ coupon } = {}) {
    await api('POST', '/cart/items', { token: C, body: { variant_uuid: variant, nursery_id: NURSERY, qty: 1 } });
    const co = await api('POST', '/checkout', { token: C, body: { address: { line1: 'QA', city: 'Test' }, ...(coupon ? { coupon } : {}) } });
    return co;
  }

  // ════ TEST 3: IDEMPOTENT PAY ═════════════════════════════════════════════
  let paidOrder;
  {
    await setStock(10);
    const before = await availability();
    const co = await buildCheckout();
    const sid = co.json?.data?.checkout_uuid;
    check('T3 checkout starts (payment_pending)', co.status === 201 && !!sid, `status=${co.status}`);

    // 8 concurrent pays on the SAME session with the same Idempotency-Key.
    const pays = await Promise.all(Array.from({ length: 8 }, () =>
      api('POST', `/checkout/${sid}/pay`, { token: C, headers: { 'Idempotency-Key': 'idem-' + sid } })));
    const orderUuids = new Set(pays.map((p) => p.json?.data?.order?.uuid).filter(Boolean));
    check('T3 8 concurrent pays mint exactly ONE order',
      orderUuids.size === 1, `distinct orders=${orderUuids.size} statuses=${pays.map((p) => p.status).join(',')}`);

    // A further explicit double-pay returns the same order (idempotent replay).
    const again = await api('POST', `/checkout/${sid}/pay`, { token: C, headers: { 'Idempotency-Key': 'idem-' + sid } });
    check('T3 replay pay returns the same order (idempotent)',
      again.status === 200 && orderUuids.has(again.json?.data?.order?.uuid),
      `order=${again.json?.data?.order?.uuid}`);

    const after = await availability();
    check('T3 stock deducted by exactly qty (1), not once-per-pay',
      before - after === 1, `before=${before} after=${after} delta=${before - after}`);

    paidOrder = [...orderUuids][0];
  }

  // ════ TEST 4: REFUND restocks exactly once; double-refund blocked ════════
  {
    const ord = await api('GET', `/orders/${paidOrder}`, { token: C });
    const sub = ord.json?.data?.sub_orders?.[0]?.uuid || ord.json?.data?.subOrders?.[0]?.uuid;
    check('T4 order exposes a sub-order', !!sub, `sub=${sub}`);

    // move sub_order into a refundable terminal state (placed -> cancelled)
    await api('POST', `/nursery/sub-orders/${sub}/transition`, { token: A, body: { to: 'cancelled' } });
    const stockBefore = await availability();

    // fire two concurrent refunds — lock must let exactly one restock.
    const [r1, r2] = await Promise.all([
      api('POST', `/nursery/sub-orders/${sub}/refund`, { token: A }),
      api('POST', `/nursery/sub-orders/${sub}/refund`, { token: A }),
    ]);
    const ok = [r1, r2].filter((r) => r.status === 200);
    const blocked = [r1, r2].filter((r) => r.status >= 400);
    const stockAfter = await availability();
    check('T4 exactly one concurrent refund succeeds', ok.length === 1,
      `ok=${ok.length} statuses=${[r1.status, r2.status].join(',')}`);
    check('T4 second concurrent refund blocked (ILLEGAL_TRANSITION)',
      blocked.length === 1 && blocked[0].json?.errors?.[0]?.code === 'ILLEGAL_TRANSITION',
      `code=${blocked[0]?.json?.errors?.[0]?.code}`);
    check('T4 stock restocked by exactly qty (1) ONCE',
      stockAfter - stockBefore === 1, `before=${stockBefore} after=${stockAfter} delta=${stockAfter - stockBefore}`);

    const third = await api('POST', `/nursery/sub-orders/${sub}/refund`, { token: A });
    check('T4 subsequent refund still rejected', third.status >= 400, `status=${third.status}`);
  }

  // ════ TEST 5: COUPON usage_limit enforced under concurrency ══════════════
  {
    await setStock(10);
    const code = 'MONEY' + rnd().toUpperCase();
    const mkC = await api('POST', '/promotions/coupons', { token: A, body: {
      code, type: 'fixed', value: 100, usage_limit: 1 } });
    check('T5 coupon created with usage_limit=1', mkC.status === 201, `status=${mkC.status}`);

    // Two independent checkout sessions on the customer, both carrying the coupon.
    const s1 = (await buildCheckout({ coupon: code })).json?.data?.checkout_uuid;
    const s2 = (await buildCheckout({ coupon: code })).json?.data?.checkout_uuid;
    check('T5 two coupon checkouts pending', !!s1 && !!s2, `s1=${s1} s2=${s2}`);

    // Pay both concurrently — only ONE redemption may commit.
    const [p1, p2] = await Promise.all([
      api('POST', `/checkout/${s1}/pay`, { token: C }),
      api('POST', `/checkout/${s2}/pay`, { token: C }),
    ]);
    const paid = [p1, p2].filter((p) => p.status === 200 && p.json?.data?.order);
    const rejected = [p1, p2].filter((p) => p.status >= 400);
    check('T5 exactly one coupon-bearing order succeeds',
      paid.length === 1, `paid=${paid.length} statuses=${[p1.status, p2.status].join(',')}`);
    check('T5 the other pay fails closed (USAGE_LIMIT_REACHED, no order)',
      rejected.length === 1 && rejected[0].json?.errors?.[0]?.code === 'USAGE_LIMIT_REACHED',
      `code=${rejected[0]?.json?.errors?.[0]?.code} status=${rejected[0]?.status}`);

    const list = await api('GET', '/promotions/coupons', { token: A });
    const row = (list.json?.data || []).find((c) => c.code === code);
    check('T5 coupon used_count == 1 (cap not overshot)', row?.used_count === 1, `used_count=${row?.used_count}`);
  }

  console.log(`\n──────── ${PASS} passed, ${FAIL} failed ────────`);
  process.exit(FAIL ? 1 : 0);
}

main().catch((e) => { console.error('SUITE ERROR', e); process.exit(2); });
