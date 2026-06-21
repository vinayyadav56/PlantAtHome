# Enterprise RBAC — Production Go-Live Readiness

_Status as of 2026-06-21. Everything below is **on `staging`** (api `vinayyadav56/PlantAtHome`, admin `PlantAtHomeAdmin`). **Nothing is on production yet.** Promotion is a separate, explicit, confirm-gated step._

**Overall verdict: READY-WITH-FIXES.** No hard deploy blockers (migrations + seeders + `composer reinstall marvel/shop` are all in `production.yml`, idempotent, and non-destructive to existing users). The recommended items below make the feature *complete and safe to verify*; the deferred items are known, safe limitations.

---

## ✅ Verified safe (no action needed)
- **All 3 new migrations are idempotent + additive** — `2026_07_05_000000_fix_admin_base_perm` (role-scoped, re-run-safe), `2026_07_06_000000_create_designations_table` (`hasTable` guard), `2026_07_06_000100_add_designation_fields_to_users` (every column `hasColumn`-guarded, all nullable/defaulted → existing user rows untouched).
- **`production.yml` runs, in order, after `migrate --force`:** `composer reinstall marvel/shop` (so new marvel code isn't stale) → `RolePermissionSeeder` (creates the `employee` role + module/submodule perms) → `DesignationSeeder` (7 designations) → `LocationSeeder`. Class names match namespaces.
- **No destructive ops on existing prod users/roles.** Seeders are `findOrCreate`/`updateOrCreate` + additive `givePermissionTo`; the only `syncPermissions` is on the new coarse-only `employee` role. super_admin / store_owner / customer / delivery-partner users are untouched.
- **No lock-out path:** `PermissionResolver::materialize` always force-includes `['staff','customer']`, so an employee can never lose admin sign-in.
- **Catalog backward-compat:** 96 perms (42 legacy 2-segment + 54 new 3-segment); every legacy perm preserved, `isValid` accepts both.

---

## 🔧 Recommended before go-live (non-blocking, but do these)
1. ~~Build the "Edit employee access" UI.~~ **✅ DONE this session.** New `GET employees/{id}` (employees.view-gated) + `pages/employees/[id]/edit.tsx` (pre-filled from the employee's current designation + effective perms) + an "Access" button per row in the employees list, wired to the existing `setAccess` endpoint. Admins can now change designation, switch to custom (grant/revoke), and edit org fields without delete-and-recreate.
2. **End-to-end verify on staging before promoting:** create a designation → onboard an employee → log in as them → confirm filtered sidebar + a non-permitted route is blocked → confirm a real vendor login is unchanged.
3. **Add a post-deploy RBAC smoke step** (see runbook) — the seeders run with `|| echo WARNING`, so a silent seeder failure deploys GREEN while leaving the `employee` role/perms missing (every designation employee then locked out) and `/api/health` still 200. The fix is a 30-second manual check, or remove `|| echo WARNING` on `RolePermissionSeeder`.

---

## 🟡 Safe to defer (known limitations — OK to ship)
- **Super-admin-only route group NOT loosened (Phase D remainder).** ~269 admin routes (users/customers/vendors **lists**, settings, command-center, reports) still require `super_admin`. So designation employees get full sidebar/page filtering + **working order & product flows** (their index routes are customer-gated; writes are module-gated), but the **lists** for those super-admin resources return 403. Net: "Customer Care", "Operations", "Inventory Manager" designations are largely functional; "Vendor Manager"/"Finance"/"Regional Manager" are partially functional until their read routes are loosened. Planned as "one module per staging→main promotion, shadow-audit first."
- **`DesignationController::update` re-materialises employees synchronously** (O(n) writes in-request). Fine at current headcount; queue it if employee counts grow large.
- **Orphan `permission-picker.tsx`** (superseded by `catalog-matrix.tsx`) — harmless, cleanup only.

---

## ⚠️ Risks to watch on promotion
- **`migrate --force` blast radius = all of staging, not just RBAC.** `git reset --hard main` + `migrate` runs *every* pending migration the staging→main PR carries. All current ones are additive/guarded, but diff `origin/main..origin/staging` migrations before promoting so the team knows the full set. _(medium)_
- **Silent seeder failure** (the `|| echo WARNING` issue above) → mitigated by the post-deploy RBAC smoke. _(medium)_
- **Admin base-perm flip is a behavioural change** for any user whose vendor access came *solely* from the internal `admin` role's `store_owner` grant (the exact bug being fixed) — they move from the vendor dashboard to the admin panel. Intended. Optionally query prod for `admin`-role users lacking the `store_owner` role/grant first. _(low)_
- **`LocationSeeder` cities derive from `delivery_pincodes`** — if empty on prod, city dropdowns stay empty (states still seed). Confirm `delivery_pincodes` is populated (should be from the prior Phase-2 promotion). _(low)_

---

## 🚀 Go-live runbook (API → Admin; shop unaffected this session)
1. **Diff** `origin/main..origin/staging` migrations/seeders to see the full deploy set.
2. **API:** open + merge PR `staging → main` (vinayyadav56/PlantAtHome). Run **API – Production Deploy** (`gh workflow run production.yml -f branch=main -f confirm=deploy-production`). Watch it green: gitleaks/test → SSH EC2 → `composer install` → `composer reinstall marvel/shop` → `migrate --force` → RolePermission/Designation/Location seeders → cache → `/api/health` 200.
3. **Admin:** merge PR `staging → main` (PlantAtHomeAdmin). Run **Admin – Production Deploy** → Vercel prod.
4. **Post-deploy RBAC smoke (critical):** confirm the `employee` role + module perms seeded (e.g. `php artisan tinker --execute="echo \Spatie\Permission\Models\Role::where('name','employee')->exists();"`), since seeders fail soft.
5. **Rollback if needed:** revert the merge commit on `main` + re-run that repo's `production.yml`; Vercel = redeploy previous deployment.

---

## 🔎 Post-deploy smoke checklist (prod)
- [ ] `api.plantathome.in/api/health` → 200
- [ ] **Super-admin login** → full admin panel + sidebar unchanged
- [ ] **Vendor (store_owner) login** → still the vendor/owner dashboard (NOT the admin panel)
- [ ] Create a **Designation** with a few permissions → saves
- [ ] **Onboard an employee** with that designation → appears in the employees list with its designation badge
- [ ] **Log in as that employee** → admin panel shows only their permitted modules; a non-permitted page = Access Denied; a non-permitted API = 403
- [ ] Place a **test order + refund** → stock restores (pre-existing fix, unrelated but part of standard prod smoke)
- [ ] `employee` role + designations exist in DB (the RBAC smoke from runbook step 4)
