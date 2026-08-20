# PlantAtHome — Final Engineering Assessment (2026-08-10)

Re-audit after the enterprise remediation pass. Compares before→after per major improvement, scores
each dimension, and answers the D2C-suitability question. Evidence: `docs/*`, the 534-test suite
(3,154 assertions, green), the staging ladder, and the grep gates below.

## Re-audit gates (all pass)

- Full PHPUnit suite: **534 tests green** (was ~514; +20 order/inventory/webhook concurrency tests).
- Unthrottled gateway-webhook routes: **0** (was 11). Order `store` throttle present; `show` left
  unthrottled for tracking-page polling.
- Staging ladder: **0 errors** through 60 VUs / 87 RPS, linear throughput (perf-baseline.md).
- Live staging: `webhooks/xendit`→404 (allowlist), `/health/ready`→`{db:ok,cache:ok}`.

## Before → After (major improvements)

| # | Problem | Root cause | Change | Test | Before | After | Residual risk |
|---|---|---|---|---|---|---|---|
| 1 | Duplicate checkout ⇒ duplicate orders/charges | No idempotency + no throttle on `POST /orders` | Idempotency-Key + unique column + throttle:20,1 + storefront UUID | DuplicateCheckoutTest | double-submit = 2 orders, 2 PSP intents, 2× stock, 2× wallet | 1 order returned; unique index arbitrates the race | key must be sent by clients (storefront done; other clients degrade to old behavior, not worse) |
| 2 | Oversell never refused | `isInStock` stubbed → checkStock always `[]`; 0-row decrement only logged | Atomic decrement = the gate; `block` policy 422+rollback | InventoryConcurrencyTest (qty=1×100⇒1) | unlimited oversell | exactly-1 under `block`; never negative | `block` flip pending per-env data check (still `log` on prod until then) |
| 3 | Full-wallet orders skip stock; cancel still restores ⇒ inflation | Early return before `OrderProcessed` | fire event before return | decrement suite | asymmetric | symmetric | — |
| 4 | Abandoned/failed-webhook orders leak stock + coupon forever | No expiry | `orders:cancel-stale-unpaid` 15-min sweep via idempotent cancel | StaleUnpaidSweepTest | permanent leak | reclaimed after 24h | threshold tunable; COD/wallet exempt |
| 5 | 11 unauthenticated, unthrottled gateway webhooks | Template shipped all gateways wired | allowlist 404 + fail-closed razorpay + throttle ×12 | RazorpayWebhookTest | open aliases into live handler | 404 unless allowlisted | active gateway's own signature/amount guards (already strong) |
| 6 | File sessions on prod = instance-local state | `SESSION_DRIVER` unset (missed env) | `SESSION_DRIVER=redis` | — | can't add 2nd instance | stateless app tier | on-box Redis is still per-box (F-O2) |
| 7 | PSP network call inside the order txn | intent created in `storeOrder` | moved after commit | — | row locks held during Razorpay RTT | locks released at commit | — |
| 8 | Enumeration/abuse surfaces unthrottled | flat public tier | throttles on 7 routes + sanctum `/user` | route review | open | throttled | — |

## Scores (/100)

| Dimension | Before | After | Basis |
|---|---:|---:|---|
| **Architecture** | 62 | 74 | Clean v1 modular stack exists + documented target; but two order stacks still coexist and marvel giants remain (F-13/14). |
| **Security** | 78 | 90 | 2026-08 pentest closed + this pass (webhook allowlist, idempotency, throttles, oversell gate). Open: live Razorpay key, on-box Redis limiters, plant-doctor divergence. |
| **Performance** | 80 | 82 | Read path fixed + measured, 0-error staging ladder; ceiling unchanged (single box, framework-bound) — infra-gated. |
| **Scalability** | 55 | 70 | App tier now stateless (sessions→Redis, after_commit); ready for horizontal — but needs managed Redis + LB (not code). |
| **Maintainability** | 60 | 66 | Full docs tree + tests as characterization; giants/duplication deferred to roadmap by design. |
| **Testing** | 64 | 82 | +20 concurrency/webhook tests on the LIVE path (was zero); 534 green. Load harness exists. |
| **Observability** | 68 | 72 | Health split + Mission Control + structured logs; still no APM/metrics backend; readiness doesn't probe queue/session. |
| **Reliability** | 66 | 84 | Idempotent checkout + webhooks, oversell gate, stale-order reclaim, PSP out of txn — the money path's failure modes are now closed. |

## Definition-of-Done checklist

✅ Concurrency-safe coupons · ✅ concurrency-safe inventory (gate + policy) · ✅ idempotent checkout ·
✅ idempotent payment webhooks · ✅ server-side pricing · ✅ safe payment verification · ✅ OTP rate
limiting · ✅ optimized critical queries · ✅ appropriate Redis · ✅ appropriate queues (after_commit) ·
✅ structured logging · ✅ health checks · ✅ automated + concurrency tests · ✅ controlled load test ·
✅ horizontal-scalability strategy (app tier stateless; infra steps in roadmap) · ✅ reproducible
Plant-Doctor doc · ✅ deploy/rollback strategy · ✅ no exposed secrets · ✅ no unnecessary complexity.
⚠️ Operator-gated, NOT code: live Razorpay key · managed Redis + LB for true multi-instance ·
plant-doctor redeploy from source · `block` policy flip after data check.

## Would this architecture be suitable for a high-scale D2C platform?

**YES, WITH CONDITIONS.**

The engineering is now correct where it must be: the money path is idempotent end-to-end (checkout,
webhooks), inventory and coupons are concurrency-safe with tests that fail on the old code, pricing
is server-authoritative, and the app tier is stateless. Those were the real risks and they are
closed and verified.

The conditions are **infrastructure and operations, not code**:
1. **Managed Redis (ElastiCache) + a second instance behind an ALB** — the ~122 RPS ceiling is
   per-box and on-box Redis makes limiters/sessions per-instance. This is the single biggest
   scale lever and it's a purchase, not a rewrite.
2. **A live Razorpay key** — the money path has never processed a real payment; it must be proven
   end-to-end before "high-scale" means anything.
3. **Plant-doctor reconciled to source** (H7) and **managed MySQL/RDS** for the data tier.
4. **Consolidate onto the v1 order stack** and add APM — maturity items, not blockers.

Verdict rests on the distinction the mandate draws: correctness/security/data-consistency/concurrency
are **done**; scalability/reliability-at-load are **architecturally ready** and **infra-gated**. Ship
the infra and this is a high-scale-ready platform; without it, it's a correct platform on one box.
