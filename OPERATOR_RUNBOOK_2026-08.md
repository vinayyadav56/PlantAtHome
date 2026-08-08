# Operator Runbook — remaining hardening (2026-08-09)

Everything code-fixable from the security audit and performance report is done and live in
production. This file lists what remains — each item needs infrastructure, a credential, or a
decision that only you can make. I physically cannot do these (no access to your AWS console,
Railway dashboards, payment keys, or DNS).

Ordered by how much they gate a real, safe, best-in-class launch.

---

## 1. 🔴 Razorpay is still on a TEST key — no real payment works

Production storefront runs `rzp_test_…`. Every checkout is a sandbox transaction; real cards fail.

**Do:** set the live key on the API's production env and rebuild config.
```
# on the EC2 box (via SSM), in /var/www/plantathome/api
set RAZORPAY_KEY_ID / RAZORPAY_KEY_SECRET to the LIVE values in .env
php artisan config:clear && php artisan config:cache
sudo systemctl reload php8.1-fpm
# and the storefront's NEXT_PUBLIC_RAZORPAY_KEY_ID → live, then redeploy the shop
```
Then run ONE real low-value order end-to-end and confirm the webhook marks it paid. Nobody has
proven the money path yet — staging has zero completed orders.

---

## 2. 🔴 Reconcile the deployed plant-doctor service with source (audit H7)

The running plant-doctor service is **not built from the repository**. It enforces API-key auth and
uses OpenAI; the repo's source does neither and imports Anthropic at module load. A deploy from the
repo would crash it.

My security fixes for the AI services are on branch **`security/service-hardening`** in the
`plantathome-ai-microservices` repo (constant-time key compare, fail-closed boot, docs off, no
wildcard CORS, plus the plant-doctor auth + SSRF guard). They are NOT deployed.

**Do:** recover whatever source the plant-doctor service is actually running, reconcile it with the
branch, and only then deploy. Until then that service is unauditable and unpatched.

---

## 3. 🔴 Rotate the secret committed to git history (audit H8)

`chatbot-service/DEPLOY_ASK_AI.md` contains a real `SHARED = …` value, reused as `SERVICE_API_KEY`,
`PERSIST_KEY`, and the monolith's `AI_CHAT_SERVICE_API_KEY`. Deleting the file does not remove it
from history — **rotation is the fix.**

**Do:** rotate that value in Railway (the chatbot service + the monolith's settings) and delete the
file going forward. A history rewrite is optional and invalidates every clone/SHA — only worth it
if the repo goes public.

---

## 4. Restrict the EC2 origin firewall to Cloudflare (audit A3 / M5)

TrustProxies trusts all proxies, so a spoofed `X-Forwarded-For` could shape the client IP every
IP-keyed rate limit uses. Cloudflare currently normalises the header (measured: a spoof shared one
counter), so it's mitigated — **but only while traffic is forced through Cloudflare.**

**Do:** in the EC2 security group, allow inbound 443/80 only from Cloudflare's published IP ranges
(https://www.cloudflare.com/ips/). If the origin answers on its public IP directly, the spoof is
live. Add edge rate-limiting rules on `/api/*otp*` and `/api/login` while you're there.

---

## 5. Managed infrastructure — the real best-in-class target

The origin is **one 2-core / 1910MB EC2 box** running Laravel + php-fpm + six queue workers + both
Next apps + nginx. That is the binding capacity constraint for a 10000cr-scale brand. What I did
this pass is the interim; the managed target:

- **Managed Redis (ElastiCache).** On-box redis-server is installed and serving (200MB cap,
  allkeys-lru, localhost). Move to ElastiCache for HA + memory headroom, then set `REDIS_HOST` to
  its endpoint. Keep `REDIS_CLIENT=phpredis`.
- **Right-size + horizontally scale the origin.** A bigger instance, then two+ behind an ALB with
  autoscaling. Cluster mode is already configured; on a bigger box the worker-count math
  (`ecosystem.config.js`, `WORKER_RSS_MB`) will yield more than one worker — see §7.
- **Managed MySQL (RDS)** with a read replica for the storefront read load.
- **CDN in front of the read API.** The API emits `s-maxage=300` and Cloudflare fronts it, but
  API-response edge caching isn't fully leveraged — the capacity model's single biggest lever
  (232 cores at 80% offload vs 555 at 0%).

---

## 6. opcache preload segfaults on the production PHP build

`preload.php` exists and the deploy dry-runs it before enabling — that dry-run **segfaults on the
prod Ubuntu PHP 8.1 build** and correctly leaves preload OFF (the guard prevented an outage). Worth
−3.3% median / −20% p95 if fixed.

**Do:** reproduce the segfault on a like-for-like Ubuntu PHP 8.1 box (NOT on prod), narrow
`preload.php`'s include list until it's stable, then let the deploy's dry-run enable it. Do not
iterate against production — each attempt burns a prod deploy.

---

## 7. Two small things I deliberately left for you

- **PM2 gives each app only ONE worker.** The `WORKER_RSS_MB` constant in both
  `ecosystem.config.js` files assumes ~900/700MB per worker; measured RSS is ~175MB, so the math
  clamps to 1. I did **not** bump it: a second Node worker on a 2-core box already running php-fpm +
  six queue workers may thrash. Tune the constant to ~200 **and** verify under load — ideally after
  §5's bigger box.
- **Delete the admin `graphql` workspace.** It is never built for production (`build:rest` only) but
  still carries old swiper/next copies that show as criticals in `yarn audit`, and an unsanitized
  `dangerouslySetInnerHTML` in dead code. Deleting it clears the audit noise and the trap.

---

## Known nuances (not action items, but worth knowing)

- **Cache: web uses Redis, console uses file.** This is standard Laravel behaviour (the scheduler's
  mutex forces the console default to file). It is benign here because *all* storefront cache-busting
  runs in web controllers (Redis→Redis); no cron/console command busts a web-read cache. If you ever
  add a console command that must invalidate the storefront cache, pin it to `Cache::store('redis')`
  explicitly.
- **Unauthenticated API requests now return 401** (were 500). If any client or monitor was matching
  the old 500, update it.
- **Token expiry is on (30 days).** Sessions older than 30 days will re-login. `POST
  /logout-all-devices` is the theft remedy.
- **Remaining dependency highs** are transitive build-tooling needing breaking majors (axios, sharp,
  postcss, etc.). All *criticals* are cleared. Schedule the majors with a full e2e regression.
- **Mobile `tar`** advisory needs an Expo major (build-time only, not shipped in the APK).
