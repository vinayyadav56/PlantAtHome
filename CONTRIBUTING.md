# PlantAtHome — Development & Deployment Guide

## Branch Strategy

```
main ─────────────────────────────────────────────► production (AWS EC2 + RDS)
  ↑
  │  PR merged after QA sign-off on staging
  │
staging ──────────────────────────────────────────► Railway API + Vercel frontends
  ↑
  │  PR from feature or hotfix branch
  │
feature/PAH-42-short-description   (one per ticket, branch from staging)
hotfix/short-description            (critical fix, branch from main)
```

### Branch rules

| Branch | Who creates it | Where it deploys | Direct commits? |
|--------|---------------|-----------------|-----------------|
| `main` | Never manually | AWS EC2 production | ❌ PRs only |
| `staging` | Never manually | Railway + Vercel staging | ❌ PRs only |
| `feature/*` | Developer, from `staging` | Nowhere (runs CI only) | ✅ yes |
| `hotfix/*` | Developer, from `main` | Nowhere | ✅ yes |

---

## Lifecycle of a Feature

```bash
# 1. Start from staging
git checkout staging && git pull origin staging
git checkout -b feature/PAH-42-add-cod-payment

# 2. Build & commit
git add . && git commit -m "feat(PAH-42): add cash on delivery option"
git push origin feature/PAH-42-add-cod-payment

# 3. Open PR → staging
#    CI runs: secret scan → dependency audit → PHP syntax + migrations test
#    Team reviews, approves

# 4. Merge → staging auto-deploys API to Railway
#    Manual trigger: deploy Shop + Admin to Vercel staging
#    QA tests on staging URLs

# 5. Open PR → main (add QA sign-off comment)
#    Same CI gates run again on main
#    Senior dev approves

# 6. Merge → manually trigger production deploy via GitHub Actions
```

## Lifecycle of a Hotfix

```bash
# 1. Branch from main (NOT staging — you want the last known-good code)
git checkout main && git pull origin main
git checkout -b hotfix/fix-payment-callback

# 2. Fix, commit, push
git commit -m "fix: handle Razorpay callback timeout"
git push origin hotfix/fix-payment-callback

# 3. Open TWO PRs simultaneously:
#    PR A: hotfix/... → main   (production fix)
#    PR B: hotfix/... → staging (keep staging in sync)

# 4. Merge PR A → manually trigger production deploy
# 5. Merge PR B → staging auto-deploys
```

---

## What Happens on Each Deploy

### API — Staging (Railway Docker)

Every push to `staging` triggers a full Docker image rebuild (~3 min). When the container starts, `start.sh` runs:

1. Writes `.env` from Railway environment variables
2. Ensures `storage/framework/` directories exist
3. Starts **nginx + php-fpm immediately** → Railway health check passes
4. **Background** (async, ~30–60 s):
   - Checks MySQL table count
   - **If 0 tables** → full install (`migrate:fresh` + seeding)
   - **If tables exist** → `php artisan migrate --force` only (pending migrations, ~10 s)
   - Idempotent: ensures permissions, roles, admin user, license file

**No re-seeding. No admin recreation on repeat deploys. Only pending migrations run.**

### API — Production (EC2, zero-downtime)

```
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force          ← pending migrations only
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo systemctl reload php8.1-fpm    ← graceful reload, existing requests finish
```

**~30–60 s. No setup, no seeding, no downtime.**

### Shop / Admin — Staging (Vercel)

Manual trigger in GitHub Actions → security scan → build check → Vercel deploy. ~3 min.

### Shop / Admin — Production (EC2)

Manual trigger → security scan → build check → SSH to EC2:
```
git pull + npm ci + next build + pm2 reload   ← zero-downtime graceful reload
```
~4–6 min.

---

## Environment Variables

**All secrets live outside committed code.** Never hardcode keys in source files.

| Where | What's stored there |
|-------|-------------------|
| Railway dashboard (env vars) | `APP_KEY`, `RAZORPAY_KEY`, `RAZORPAY_SECRET`, `SENDGRID_API_KEY`, DB vars |
| Vercel project settings | `NEXT_PUBLIC_RAZORPAY_KEY_ID`, API endpoints, site URLs |
| GitHub Secrets | `EC2_SSH_KEY`, `RAILWAY_TOKEN`, `VERCEL_TOKEN`, `STAGING_RAZORPAY_KEY_ID`, `PROD_RAZORPAY_KEY_ID` |
| EC2 `/var/www/plantathome/api/.env` | Production Laravel `.env` (not in git) |

### GitHub Secrets to configure

```
# Shared infrastructure
EC2_HOST                   = 3.7.30.178
EC2_USER                   = ubuntu
EC2_SSH_KEY                = (contents of plantathome-key.pem)
RAILWAY_TOKEN              = (from Railway dashboard → Account → Tokens)
VERCEL_TOKEN               = (from Vercel dashboard → Account → Tokens)
VERCEL_ORG_ID              = (from Vercel project settings)
VERCEL_SHOP_PROJECT_ID     = (from Vercel shop project settings)
VERCEL_ADMIN_PROJECT_ID    = (from Vercel admin project settings)

# Razorpay — staging uses test keys, production uses live keys
STAGING_RAZORPAY_KEY_ID    = rzp_test_...
PROD_RAZORPAY_KEY_ID       = rzp_live_...
```

---

## Environment-Specific Code

**Rule: zero code differences between staging and production.** All environment differences are in variables, not in code.

The `APPLICATION_MODE` env var (`staging` | `production`) already gates:
- TypeScript/ESLint strictness in builds
- Payment gateway mode (test vs. live)

Use this pattern for any new environment-conditional behavior:

```typescript
// ✅ Correct — flag in env var
if (process.env.APPLICATION_MODE === 'staging') {
  // staging-only UI banner
}

// ❌ Wrong — hardcoded differences in code between branches
```

For features that need to roll out gradually, use the `app_settings` JSON column in the settings table as a feature flag — toggle it per environment without changing code.

---

## CI Gates Summary

All pipelines run these gates in order (failure stops the chain):

1. **Secret scan** (Gitleaks) — no API keys / passwords in committed code
2. **Dependency audit** (yarn/composer audit) — no high/critical CVEs in packages
3. **Filesystem scan** (Trivy) — no critical CVEs in code or Docker image
4. **Build check** — TypeScript compiles, Next.js builds, PHP migrations run cleanly
5. **Deploy** — only if all above pass (production requires manual confirmation)
6. **Health check** — curl the live URL, fail the workflow if not 200

Production deploy additionally requires typing `deploy-production` in the GitHub Actions confirmation input.
