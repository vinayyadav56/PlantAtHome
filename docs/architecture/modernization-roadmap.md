# PlantAtHome — Modernization Roadmap

Deferred-by-design work, ordered by leverage. Each item is small enough to ship independently with
the characterization-test → refactor → regression pattern. P0/P1 engineering items from the 2026-08
audits are DONE (see [findings.md](findings.md)); this is what remains.

## Now / operator-gated (unblock before scale events)

1. **Razorpay LIVE key** on prod + `config:cache` + one real ₹1 transaction end-to-end (runbook #1 —
   the money path has never run live).
2. **Managed Redis (ElastiCache)** + point cache/sessions/limiters at it — the last per-instance
   state. Then **second app instance + ALB** (the ~122 RPS ceiling is per-box; horizontal is the
   only lever without PHP-runtime surgery).
3. **Plant-doctor reconciliation** (H7): follow docs/operations/production-readiness.md §Plant
   Doctor — capture deployed env, diff against repo, redeploy from source in a window. Source is
   healthy (pinned deps, SSRF guard, Dockerfile).
4. **Staging parity**: set Railway `CACHE_DRIVER=redis`(+ managed) & `SESSION_DRIVER` to mirror prod.
5. **Flip `INVENTORY_OVERSELL_POLICY=block`** on staging → prod after the data check
   (published+purchasable products with `quantity <= 0` — see production-readiness.md §Inventory).

## Next quarter (engineering)

6. **Storefront checkout → `/api/v1` Sales** behind a flag (F-14). The v1 stack already has
   idempotency, reservations w/ TTL + release sweep, and the e2e money-path suite. Cohort rollout;
   marvel order routes 410 after parity.
7. **Queue operability**: Horizon (or at minimum queue depth/failed-job alerting into Mission
   Control); single source for worker definitions (F-18); `queue:restart` hook on env-affecting
   deploys (ConfigOverlay lesson).
8. **Stock model consolidation** (F-12): make v1 InventoryService the owner; `products.quantity`
   becomes a read-model; enable vendor reservations (currently flag-OFF, assigns on failure);
   unsigned `quantity` migration after a negative-value data check (F-20).
9. **Security dataset → generated** (F-17): emit the admin security page dataset from a checked-in
   YAML/JSON source of truth like `build_report.mjs` does for perf.
10. **Delete `admin/graphql`** (F-19) — dead, unpatched, still trips `yarn audit`.

## Opportunistic (only when touching the file anyway)

11. Decompose the five marvel giants (F-13) — extract services out of
    ProductController/UserController/OrderRepository along the seams the v1 modules already model.
    Never as a standalone rewrite.
12. Admin form-kit dedupe (`form/` vs `forms/`, F-15) and shop/admin `design-system.ts` into a
    shared package (F-16).
13. Unify react-query: either finish the shop's v5 migration (remove the compat shim) or hold —
    but stop the admin/shop split from growing new divergent data-layer code.
14. Bundle components per-variant (`expandToInventoryUnits`, F-21) when bundles need sized
    components.

## Explicitly rejected (and why)

- **Microservices/Kubernetes/Kafka/CQRS**: the measured bottleneck is PHP framework overhead on one
  box, not domain coupling. Two app instances + managed data stores solve the next 10× cheaper.
- **Big-bang marvel rewrite**: the v1 stack already exists; strangler by route keeps revenue safe.
- **Frontend redesigns**: engineering-quality only (mandate §15); the design system is already
  token-driven and live-themeable.
