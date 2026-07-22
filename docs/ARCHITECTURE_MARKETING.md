# Enterprise Marketing Automation — Architecture

A HubSpot/Mailchimp-style marketing module for PlantAtHome: build audiences with
SQL, author email/SMS/WhatsApp templates, schedule campaigns, and send them
**asynchronously** through the queue with full delivery tracking, retries and
analytics.

> **Golden rule:** nothing is ever sent from an HTTP request. Every send flows
> through Laravel Queues + the Scheduler. The API only ever writes definitions
> and *enqueues* work.

Built as a **V2 bounded-context module** — `app/Modules/Marketing` — mounted at
`/api/v1/marketing`, gated by the `marketing.manage` permission. Laravel 10,
database queue driver (dedicated `marketing` queue + worker), MySQL.

---

## 1. System architecture

```
Admin UI (Next.js /marketing/*)                    ← operators
        │  V2HttpClient (dual-session JWT)
        ▼
/api/v1/marketing/*  (v1.auth + v1.can:marketing.manage)
        │
   ┌────┴───────────── Application services ─────────────────┐
   │ AudienceService   TemplateService   CampaignService     │
   │ AudienceQueryRunner  CampaignRunner  DeliveryService     │
   │ AnalyticsService   DashboardService  ChannelManager      │
   └────┬───────────────────────────────┬────────────────────┘
        │ writes (definitions)          │ enqueues
        ▼                               ▼
     MySQL (marketing_*)          marketing queue (DB driver)
                                        │
                              ┌─────────┴──────────┐
                              │ GenerateCampaignBatchesJob  (materialize)
                              │ SendEmailJob / SendSmsJob / SendWhatsappJob
                              └─────────┬──────────┘
                                        │ ChannelManager → ChannelSender
                                        ▼
                        SendGrid · MSG91 · WhatsApp Cloud API
                                        │ outcome + webhooks
                                        ▼
                        marketing_notifications + marketing_delivery_logs
        ▲
   marketing:dispatch-due (scheduler, every minute) launches due campaigns
```

**Layering** (per module): `Domain/` (pure rules — SQL guard, statuses,
variable mapper, schedule math), `Application/` (use-case services, DTOs, the
channel manager + renderer), `Infrastructure/` (Eloquent models + channel
adapters), `Http/` (controllers, requests, resources, routes), `Jobs/`,
`Console/`, `Providers/`.

---

## 2. ER diagram

```
marketing_audiences 1─┬─* marketing_audience_versions   (immutable snapshots V1,V2…)
                      │
marketing_templates 1─┴─* marketing_template_versions

marketing_campaigns *─1 marketing_audiences
marketing_campaigns *─1 marketing_audience_versions      (pinned at launch)
marketing_campaigns 1─* marketing_campaign_templates *─1 marketing_templates  (1 per channel)
marketing_campaigns 1─* marketing_campaign_runs
marketing_campaign_runs 1─* marketing_campaign_jobs      (a batch = 1 Send*Job)
marketing_campaign_runs 1─* marketing_notifications
marketing_campaign_jobs 1─* marketing_notifications      (batch_id)
marketing_notifications 1─* marketing_delivery_logs      (status/provider audit)
marketing_queue_logs                                     (job lifecycle audit)
```

Conventions: `bigIncrements id` (internal) + `uuid` (wire); JSON for structured
payloads; `decimal` never `double`; intra-module FKs only (the acting admin is a
`created_by_uuid`, no cross-module FK); soft-deletes on the three aggregates.

---

## 3. Database schema (11 tables)

| Table | Purpose |
|---|---|
| `marketing_audiences` | saved audience definition (name, SQL, current_version) |
| `marketing_audience_versions` | frozen result snapshot per version (rows JSON, count, columns) |
| `marketing_templates` | channel template head (content JSON, extracted variables) |
| `marketing_template_versions` | template content history |
| `marketing_campaigns` | audience × channels × templates × schedule + throttle/batch/retry |
| `marketing_campaign_templates` | channel → template binding (unique per channel) |
| `marketing_campaign_runs` | one execution instance + status tallies |
| `marketing_campaign_jobs` | a batch (unit of a Send*Job) + attempt/status |
| `marketing_notifications` | materialized per-recipient-per-channel message + lifecycle |
| `marketing_delivery_logs` | append-only status/provider transition audit |
| `marketing_queue_logs` | dispatched/processing/completed/failed/retrying audit |

Hot indexes: `notifications(run_id,status)`, `(campaign_id,status)`,
`(batch_id,status)`, `provider_message_id`; `campaigns(status,next_run_at)` for
the scheduler poll; `campaign_runs(campaign_id,status)`.

---

## 4. Audience Builder — safety

Operator SQL is validated by **`SqlSelectGuard`** (defence in depth, all on a
copy with comments + string/backtick literals stripped so keywords can't hide or
false-trip):

1. non-empty, ≤ 20k chars;
2. exactly one statement (no stacked `;`);
3. must start with `SELECT` (no CTE/`SHOW`/`PRAGMA`);
4. blocklist of write/DDL/privilege/file/lock/timing keywords
   (`INSERT UPDATE DELETE DROP ALTER CREATE TRUNCATE REPLACE GRANT SET INTO
   OUTFILE LOAD SLEEP BENCHMARK …`) — note MySQL makes `INTO` optional for
   `INSERT`/`REPLACE`, so those verbs are blocked as words, not just via `INTO`;
5. optional table allow-list (`MARKETING_AUDIENCE_ALLOWED_TABLES`).

Execution (**`AudienceQueryRunner`**) adds runtime guards: the query is wrapped
as a derived table (`SELECT … FROM (userSQL) AS _pah LIMIT n`) so a trailing
clause can't escape and an exact `COUNT(*)` comes from the same wrapper; a MySQL
`MAX_EXECUTION_TIME` optimizer hint caps runtime; rows are LIMITed at the DB
(cap `MARKETING_AUDIENCE_MAX_ROWS`, default 100k); everything runs in a
**transaction that is always rolled back**.

**Snapshots / versioning:** saving or refreshing an audience freezes the full
result set into a new immutable `marketing_audience_versions` row (V1, V2, …).
Campaigns pin a version at launch, so the recipient set never shifts under a
running campaign.

---

## 5. Templates + variables

Channel-shaped `content` JSON (email: subject/html/header/footer/buttons; sms:
body; whatsapp: body/template/media). `VariableMapper` extracts `{{tokens}}` for
auto-mapping to audience columns, and renders per recipient (missing → empty,
case-insensitive column match). Each save freezes a template version.

Variables: `{{name}} {{email}} {{phone}} {{city}} {{order_number}}
{{plant_name}} {{delivery_date}}` (any audience column works).

---

## 6. Campaign → queue flow (sequence)

```
POST /campaigns/{id}/send
  └ CampaignService.launch(): validate (audience snapshot + template per channel),
       pin audience version, create CampaignRun(pending), publish CampaignLaunched (outbox),
       dispatch GenerateCampaignBatchesJob → RETURN (no send)
  └ GenerateCampaignBatchesJob (marketing queue, tries=1):
       CampaignRunner.materialize(): decode pinned snapshot →
         per channel: render each recipient → batch_size batches →
           create marketing_campaign_jobs + batch-insert marketing_notifications(queued) →
           dispatch SendEmailJob/SendSmsJob/SendWhatsappJob(batchId)
  └ Send*Job (tries=3, backoff, timeout 280s):
       pick notifications still sendable (queued|retrying) →
         ChannelManager.send() → DeliveryService.recordSendResult():
           ok → sent (+provider_message_id);  fail → retrying (< max_retries) else failed
       if any retrying remain and attempt < max_retries → re-dispatch this batch (backoff)
       else finalizeBatch → finalizeRunIfComplete (run completed once nothing in flight)
```

100k recipients × 1k batch_size ⇒ 100 batches ⇒ 100 Send jobs, drained by the
dedicated `marketing` worker without touching the fast `default` (OTP/order)
queue.

**Idempotency:** materialize only runs for a `pending` run; a Send job only
picks *sendable* notifications and leaves them sendable until a result is
recorded — so a crash/timeout mid-batch safely resumes the unsent tail without
re-sending anyone.

---

## 7. Scheduler

`marketing:dispatch-due` (every minute, `withoutOverlapping`) launches any
campaign whose `next_run_at ≤ now`. Recurring campaigns (`daily/weekly/monthly/
cron`) re-arm `next_run_at` inside `launch()` via `ScheduleType` (cron math in
the campaign timezone, returned UTC). `now` campaigns are launched manually via
the send endpoint; `once` fires a single time.

---

## 8. Notification service (channels)

`ChannelSender` interface → `ChannelManager` (DI-replaceable registry, the spec's
"NotificationInterface"). Adapters reuse the platform's existing, working
providers:

- **EmailChannel** → SendGrid (HTTPS v3 API mailer when keyed, else default).
- **SmsChannel** → `Msg91Gateway` (DLT Flow API; needs `MSG91_FLOW_ID`).
- **WhatsappChannel** → `WhatsappGateway` (Cloud API notify template).

A `dispatch_enabled=false` kill-switch turns every channel into a dry-run
(materialize + queue run end-to-end, nothing leaves the building) for rehearsal.
**Future channels** (push, in-app, voice, webhook) = implement `ChannelSender`,
register it in `ChannelManager` — no other change.

---

## 9. Delivery tracking + Retry Center

Statuses: `queued → processing → sent → delivered → read`, plus `failed /
retrying / cancelled`. Every transition appends a `marketing_delivery_logs` row.
Provider delivered/read arrive via `POST /notifications/{id}/status` (webhook
equivalent), rank-guarded so an out-of-order callback can't downgrade.

**Retry Center** (`POST /campaigns/{id}/retry`, scope `failed|selected|all`)
re-batches the target notifications into a **new run with new queue jobs** — it
never touches the framework `failed_jobs` table.

---

## 10. Analytics

Per-campaign funnel (queued/sent/delivered/read/failed), per-channel breakdown,
delivery/read/failure rates, and a 30-day daily time-series (from the delivery
log) for the admin apexcharts. Open/CTR/conversion are exposed as
forward-looking placeholders (need open-pixel + link tracking).

---

## 11. REST API

All under `/api/v1/marketing`, `v1.auth` + `v1.can:marketing.manage`:

```
GET/POST                 audiences
POST                     audiences/preview          (dry-run, never persists)
GET/PATCH/DELETE         audiences/{uuid}
POST                     audiences/{uuid}/refresh
GET                      audiences/{uuid}/versions/{versionUuid}
GET/POST                 templates
GET/PATCH/DELETE         templates/{uuid}
GET/POST                 campaigns
GET/PATCH/DELETE         campaigns/{uuid}
POST                     campaigns/{uuid}/send | retry | pause | resume
GET                      campaigns/{uuid}/runs | analytics
GET                      dashboard
GET                      notifications                (filter campaign/run/status/channel/q)
GET                      notifications/{uuid}         (+ status timeline)
POST                     notifications/{uuid}/status
```

Uniform envelope `{ success, data, meta, errors }`; validation/domain failures
→ 422 with a machine `code`.

---

## 12. Admin UI

`/marketing` (Next.js, sidebar group "Marketing Automation"):
Dashboard · Audiences (SQL editor + live preview + versions) · Templates
(per-channel editor + variable insert) · Campaigns (list + 6-step wizard:
Audience → Channels → Templates → Schedule → Advanced → Review) · Campaign detail
(funnel + rates + time-series chart + runs + retry) · Delivery Logs (filters +
status timeline). react-query v3 over `V2HttpClient`.

---

## 13. Security

SELECT-only allow-listing · query timeout + row cap · rolled-back transaction ·
RBAC (`marketing.manage`, granted to admin + super_admin) · audit trails
(delivery + queue logs) · uuids on the wire (never internal ids) · no cross-module
FKs.

## 14. Performance

Dedicated Redis-ready `marketing` queue + worker · chunked batch inserts
(`array_chunk` 500) · DB-side LIMIT/COUNT · hot composite indexes · eager loads
on list endpoints · idempotent resume (no re-send). Horizon-compatible by design
(queue name is config-driven); runs on the DB driver today.

## 15. Testing

`tests/Unit/Marketing` (SQL guard bypass matrix, variable mapper) +
`tests/Feature/MarketingAutomation` (audience preview/save/refresh versioning,
RBAC 403, end-to-end campaign send→materialize→deliver→analytics with sync queue
+ dry-run). All green.

## 16. Production deployment

- API: register provider in `config/app.php`; migrations auto-run on boot;
  `IdentityAccessSeeder` (idempotent, every deploy) grants `marketing.manage`;
  add the `queue-worker-marketing` supervisord program (staging/Railway) — on
  EC2 add an equivalent systemd unit; `marketing:dispatch-due` runs via the
  existing `schedule:run` loop.
- Providers: set `MSG91_FLOW_ID` (DLT), `WHATSAPP_NOTIFY_TEMPLATE`,
  `SENDGRID_API_KEY` for real sends; `MARKETING_DISPATCH_ENABLED=false` to
  rehearse.
- Admin: ships with the standard admin deploy.

## 17. Config (`config/marketing.php`)

`MARKETING_QUEUE`, `MARKETING_AUDIENCE_MAX_ROWS`, `_PREVIEW_ROWS`, `_MAX_EXEC_MS`,
`_ALLOWED_TABLES`, `MARKETING_DEFAULT_BATCH_SIZE`, `_MAX_RETRIES`,
`_THROTTLE_PER_MIN`, `_TIMEZONE`, `MARKETING_DISPATCH_ENABLED`.

## 18. Extensibility

New channel = one `ChannelSender` + registry line. New schedule kind = extend
`ScheduleType`. The audience/template/campaign/delivery split keeps each concern
independent, so push/in-app/voice/webhook and open/click tracking are additive.

---

## 19. Known limitations & follow-ups

This module was adversarially reviewed (22 findings; the correctness/security
ones are fixed). Three are intentional trade-offs, tracked here:

1. **Audience SQL runs as the app DB user (security boundary = ops, not code).**
   The audience builder lets a `marketing.manage` operator run arbitrary
   SELECTs. The `SqlSelectGuard` (SELECT-only, no writes/DDL/file/UDF/executable-
   comments) + the optional table allow-list are **defence in depth, not the
   trust boundary** — regex allow-listing on raw SQL is fundamentally unsound
   (sub-selects, exotic joins). **Recommended for prod: point the audience
   runner at a dedicated least-privilege, read-only MySQL user** GRANTed SELECT
   only on the audience-relevant tables (and no FILE/EXECUTE), via a separate DB
   connection. Until then, the capability is scoped by trusting the
   `marketing.manage` role.

2. **Audience snapshots are capped at `MARKETING_AUDIENCE_MAX_ROWS` (100k) and
   decoded whole during materialization.** The frozen rows live in one JSON
   column; `CampaignRunner` json_decodes the whole snapshot in the queued
   worker. Fine to ~100k on a normal worker; beyond that, move snapshot rows to
   a dedicated `marketing_audience_members` child table and cursor them. The
   materialize job is already off the request path and batch-inserts in chunks.

3. **Recurring `daily/weekly/monthly` honor the chosen day + time in the
   campaign timezone; a campaign whose timezone differs from `app.timezone` can
   shift the time-of-day by the offset** (the operator's naive `scheduled_at` is
   parsed in the app tz). The common case (campaign tz = app tz = Asia/Kolkata)
   is exact. Fix = parse `scheduled_at` in the campaign's own tz.

Also intentionally simple: the `notifications/{id}/status` endpoint is the
manual/admin stand-in for real provider delivery webhooks (Meta/SendGrid event
callbacks) — wiring signature-verified provider webhooks is the natural next step
and slots straight into `DeliveryService::recordProviderStatus`.
