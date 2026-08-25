# PlantAtHome Media System

Centralized, versioned, environment-safe media for every entity (products first).
One S3 bucket (`plantathome-media-prod`) is **shared by staging and production**
and has **no versioning** — the safety model therefore lives in this system:
immutable objects, env-prefixed keys, a DB state machine, and logical deletion
with retention. All writes go through one door: `Marvel\Services\Media\MediaService`.

Core invariants (enforced in code, then by IAM):

1. **An S3 key is written once, ever.** A changed image is a new version.
   `MediaService::putNew()` refuses any existing key. This is what makes
   `Cache-Control: immutable` + CloudFront safe, and cross-env sharing possible.
2. **Publish / retire / rollback are DB pointer flips.** Zero S3 traffic.
3. **Only the origin environment may touch an object**, and only while the
   version is `draft`/`processing`/`rejected` (`MediaItemVersion::OBJECT_MUTABLE`).
4. **URLs come from one place** (`MediaUrlService`); the base is
   `filesystems.disks.s3.url` (`AWS_URL` → CloudFront) with raw-S3 fallback,
   so the CDN is an env flip, not a code change.

---

## Architecture

```
                          PLANTATHOME
              ┌─────────────────┴─────────────────┐
     staging app (Railway)               production app (EC2)
     MEDIA_ENV=staging                   MEDIA_ENV=production
              │                                   │
        MediaService  ◄── the single write door ──►  MediaService
              │                                   │
     staging MySQL                        production MySQL
     (refreshed FROM prod;               media_items / media_item_versions /
      rows churn, uuids stable)          media_attachments
              │                                   │
              └────────────── writes ─────────────┘
                                │
              S3: plantathome-media-prod  (ONE shared bucket → private at phase 6)
                media/p/**   prod-owned    (staging: read-only)
                media/s/**   staging-owned (prod never writes here)
                plants/**, ai-batches/**, ai-instant/**, {id}/file   legacy (frozen)
                                │
                                │ Origin Access Control (OAC)
                                ▼
                       CloudFront (AWS_URL, ACM cert)
                                ▼
                            customer
```

### One object, both environments

A LIVE object is shared: both DBs point at the same immutable key. Nothing about
serving an image requires copying it between environments.

```
prod DB:     media_item IMG (uuid 9f2c…) ── live_version_id ──► V3 (LIVE)
staging DB:  media_item IMG (uuid 9f2c…) ── live_version_id ──► V3 (LIVE)
                                                                 │
                       both resolve the SAME key ────────────────┘
        s3://…/media/p/products/9f2c…/v3/original.jpg   (written once, never mutated)
```

### Draft vs live — staging deletes cannot dent production

Drafts are env-owned; live objects are immutable for everyone. Staging deleting
its V4 draft removes only `media/s/**` objects and leaves V3 serving:

```
IMG (uuid 9f2c…)
 ├─ V3  LIVE   media/p/products/9f2c…/v3/…   ◄─ served everywhere
 └─ V4  DRAFT  media/s/products/9f2c…/v4/…   (staging experiment)

staging: DELETE /media/9f2c…/versions/4
 ├─ allowed: V4 is draft AND origin_env=staging  (deleteDraft guards both)
 ├─ deletes media/s/…/v4/* objects + the row
 └─ V3 untouched — customers never see a flicker
```

### Publishing

```
upload ──► V(n) draft ──queue──► processing ──► ready ──► approved ──► LIVE
   │            (ProcessMediaVersionJob:                      │
   │             webp variants on the `images` queue)         │ publish = one TX:
   │                                                          │  old live → retired
   └─ objects written ONCE here; every later arrow            │  item.live_version_id → V(n)
      is a DB status change only                              │  denormalize (product_images,
                                                              ▼   products.image/gallery)
                                                        old LIVE → RETIRED
                                                        (objects stay until gc retention)
```

`publish()` also accepts a `ready` version when called with a user id —
approve+publish is one admin gesture (`media.publish` implies review).

### Rollback

```
IMG: V2 retired ─┐                        rollback(item, V2):
     V3 LIVE ────┤   no upload, no copy    V3 → retired
                 └────────────────────►    V2 → LIVE  (pointer flip)
Guard: target must be RETIRED and not purged (purged_at null) —
retention (below) is what keeps rollback possible.
```

### Staging DB refresh

Product **ids differ between environments**; the media **uuid is the stable
identity** and the S3 key embeds the uuid, never a row id. A refresh rebuilds
staging rows from prod, and every pointer still resolves:

```
before refresh                         after refresh (staging DB ⟵ prod dump)
prod:    product 4123 ─ att ─ IMG(9f2c…)   prod:    product 4123 ─ att ─ IMG(9f2c…)
staging: product  987 ─ att ─ IMG(9f2c…)   staging: product 4123 ─ att ─ IMG(9f2c…)
                                                    (staging-only rows gone)
key: media/p/products/9f2c…/v3/…  — contains NO product id, NO media_item id.
Staging's own media/s/** objects for vanished rows become orphans → media:gc sweep.
```

---

## Data model

`packages/marvel/database/migrations/2026_08_26_000001_create_media_items_tables.php`

| table | purpose | notable columns |
|---|---|---|
| `media_items` | Stable identity. | `uuid` (unique — survives refreshes, embedded in keys), `entity_hint` (path hint only: 'products', …), `live_version_id` (nullable, **no FK** — circular), `origin_env`, `meta` JSON (alt/source/attribution/adoption markers), `created_by` |
| `media_item_versions` | Immutable pointer at S3 objects. | `version_number` (unique per item), `status`, `origin_env`, `original_key` (nullable — external adoptions), `external_url`, `variants` JSON `{thumbnail,small,medium,large}`, `mime/width/height/size_bytes/checksum`, `uploaded_by/approved_by/approved_at/published_at/retired_at`, `purged_at` (physical-delete audit trail — the row is never dropped in prod), `rejection_reason`. FK to items is `restrictOnDelete`. |
| `media_attachments` | Item ⇄ entity binding. | morph `attachable_type/id`, `role` (main/gallery/banner/logo/…), `position` (ordering lives HERE — reorder creates no versions), unique (entity, item, role) |
| `product_images.media_item_id` | (migration 000002) marks legacy rows adopted/owned by the media system. | |

### Lifecycle state machine (`MediaItemVersion::TRANSITIONS`)

```
draft ──► processing ──► ready ──► approved ──► live ──► retired
  │            │           │           │                    │
  └────────────┴───────────┴───────────┴──► rejected        └──► live   (rollback)
                                            (terminal)
```

Anything not in the map throws `MarvelBadRequestException`
(`MediaService::transition()`). Object mutation/deletion is legal only in
`OBJECT_MUTABLE` = draft / processing / rejected, and only by the origin env.

---

## Key layout

```
media/{p|s}/{entity_hint}/{media_uuid}/v{n}/original.{ext}
media/{p|s}/{entity_hint}/{media_uuid}/v{n}/{thumbnail|small|medium|large}.webp
```

(`MediaUrlService::storageKey()`; variants land beside the original —
`MediaVariantGenerator`.)

- **Why the env segment**: it is the only thing that makes the shared-bucket
  split **IAM-enforceable**. S3 policies scope by key prefix; `media/s/*` vs
  `media/p/*` lets staging's role be denied prod writes at the AWS layer, not
  just by application code. Legacy layouts (`plants/{slug}/n.jpg`,
  spatie `{media.id}/file`) cannot be split this way — which is why they stay
  frozen and adopted-in-place until phase 6.
- **Immutability rule**: every key under `v{n}/` is written exactly once.
  `putNew()` throws on an existing key; the variant generator skips existing
  keys; all objects are uploaded with `Cache-Control: public, max-age=31536000,
  immutable`. Never pass `'visibility'` to an S3 put — the bucket is
  Bucket-owner-enforced and rejects ACLs.
- URL building percent-encodes each key segment (`rawurlencode` per path part);
  when deriving a key **from** a stored URL, `urldecode` first (historic gotcha:
  encoded-vs-raw mismatches made deletes silent no-ops).

## Environment rules

`MEDIA_ENV` deliberately does **not** derive from `APP_ENV` (staging reports
`production` there — TESTDATA_ENV precedent). Default: `staging` when
`RAILWAY_ENVIRONMENT` is set, else `production`.

| action | staging | production |
|---|---|---|
| Read any object (media/p, media/s, legacy) | ✅ | ✅ |
| Upload new versions (PUT) | ✅ under `media/s/**` only | ✅ under `media/p/**` |
| Publish / retire / rollback (DB pointer flips in own DB) | ✅ — touches no objects, so safe | ✅ |
| Delete objects of own-env draft/processing/rejected versions | ✅ | ✅ |
| Touch any `media/p/**` object | ❌ code guard today, IAM Deny at phase 5 | ✅ |
| Touch legacy prefixes (`plants/**`, `{id}/file`, `ai-*`) | ❌ (IAM Deny on `plants/*`) | ✅ until phase 6 |
| gc: purge retired past retention | ❌ (prod-origin rows skipped) | ✅ |
| gc: delete rejected rows+objects, orphan sweep | ✅ (`media/s/**` only) | ❌ |

## Deletion model

Physical deletion is never user-facing for anything that was ever live:

1. **Logical retire** — un-publish or being superseded flips status to
   `retired` (`retired_at` stamped). Objects untouched; rollback stays possible.
2. **Retention** — `media:gc` (prod) purges objects of `retired` versions only
   after `MEDIA_RETIRED_RETENTION_DAYS` (default 30), stamping `purged_at`
   (row kept as audit trail). **Invariant: retention MUST exceed the staging
   DB-refresh cadence** — a stale staging DB pointing at a just-retired prod
   object is the one cross-env hazard.
3. **gc** — the only scheduled physical-delete path, strictly own-env:
   - prod: retired-past-retention under `media/p/**`; adopted legacy keys
     default-skipped (`--include-adopted` lifts that only after
     `media:rewrite-urls` has landed and `media:audit-refs` exits 0).
   - staging: rejected staging versions >7 days (rows AND objects — staging
     history is disposable), then an orphan sweep of `media/s/**` keys no
     version row references, older than `MEDIA_ORPHAN_MIN_AGE_DAYS`
     (in-flight-upload guard).
4. The only user-facing S3 delete is `deleteDraft()` — own-env,
   draft/processing/rejected only.

## Endpoints

All under the authenticated REST group in `packages/marvel/src/Rest/Routes.php`
(literal paths precede `media/{uuid}` — the wildcard would swallow them).
Permissions are Spatie `<module>.<action>` on guard `api`; super_admin bypasses
via `Gate::before`. Role matrix: **admin** = all four; **staff** and
**store_owner** = view + upload; approve/publish stay admin-only.

| method + path | action | permission |
|---|---|---|
| `POST /media` | upload → new item + v1 draft (`MediaUploadRequest`: jpg/jpeg/png/webp/gif, no SVG, max `media.max_upload_kb`) | `media.upload` |
| `GET /media/{uuid}` | item + versions | `media.view` |
| `GET /media/pending-approvals` | review queue (`ready` versions) | `media.view` |
| `GET /media/for-entity/{type}/{id}` | attachments for an entity | `media.view` |
| `POST /media/{uuid}/versions` | new draft version v{n+1} | `media.upload` |
| `DELETE /media/{uuid}/versions/{version}` | delete draft (env+status guarded) | `media.upload` |
| `POST /media/attach` · `POST /media/detach` · `PATCH /media/reorder` | bind/unbind/reposition (no versions created) | `media.upload` |
| `POST /media/{uuid}/versions/{version}/approve` | ready → approved | `media.approve` |
| `POST /media/{uuid}/versions/{version}/reject` | → rejected (+reason) | `media.approve` |
| `POST /media/{uuid}/versions/{version}/publish` | → live (accepts `ready` = approve+publish) | `media.publish` |
| `POST /media/{uuid}/rollback/{version}` | retired → live pointer flip | `media.publish` |
| `POST /media/{uuid}/retire` | un-publish item | `media.publish` |

Frontends keep reading the legacy `{id, original, thumbnail}` payload —
`MediaUrlService::attachmentPayload()` is byte-compatible, and publish/attach
denormalize into `product_images` + `products.image/gallery` via the existing
`syncImageColumns()`.

## Commands

All support `--dry-run` where destructive, and `--force` skips confirmation (CI).
**Production has no interactive shell path** (EC2 double-firewalled; railway ssh
writes blocked) — run these on prod as modes of the `prod-data-op` GitHub
Actions workflow (`gh workflow run prod-data-op …`), the same way the image
purge ran. Add a mode per command before phase rollout.

| command | what | usage |
|---|---|---|
| `media:adopt-existing` | Backfill items/versions/attachments from legacy `product_images` (nothing copied — existing keys adopted verbatim as LIVE v1; adopted = outside `media/p/` so gc default-skips them). Refuses the whole run on any unrecognized bucket-host URL layout. | `--dry-run` → counts+samples; `--force` → adopt (per-product atomic, re-runnable); `--rollback` → remove adopted rows (refused if any item grew a v2) |
| `media:rewrite-urls` | Swap raw S3 base → CDN base (`AWS_URL`) across the URL registry (bare columns AND JSON blobs, both raw and `\/`-escaped encodings). Snapshots every changed cell into `pah_media_url_backup` first, in-transaction. | `--dry-run` → per-table counts; `--force`; `--rollback` → restore snapshots |
| `media:audit-refs` | Read-only census of remaining raw-S3-host references over the same registry. **Exit 1 if any remain** — the CI gate before `media:gc --include-adopted` or a CDN-only policy. | `media:audit-refs` |
| `media:gc` | The only scheduled physical delete; behavior per `MEDIA_ENV` (see Deletion model). | `--dry-run`; prod: `--include-adopted` only after audit-refs is clean |

## Environment variables

| var | default | meaning |
|---|---|---|
| `MEDIA_ENV` | `staging` if `RAILWAY_ENVIRONMENT` set, else `production` | which prefix this deployment may write + which objects it may delete. Never derive from `APP_ENV`. |
| `MEDIA_RETIRED_RETENTION_DAYS` | 30 | retired→purge delay. Must exceed staging refresh cadence. |
| `MEDIA_ORPHAN_MIN_AGE_DAYS` | 7 | min object age before the staging orphan sweep may delete an unreferenced `media/s/` key. |
| `MEDIA_QUEUE` | `images` | variant-generation queue — exists on prod systemd AND staging supervisord; a new name means editing both. |
| `MEDIA_MAX_UPLOAD_KB` | 10240 | one limit for every upload path (ends the 5MB-request vs 10MB-media-library split). |
| `AWS_URL` | — | `filesystems.disks.s3.url` = the CDN base. Unset ⇒ raw S3 host. Changing it requires `config:cache` + `queue:restart`. |

## IAM policies

Attach at phase 5. The env prefix in the key is what makes these possible.

**plantathome-app-staging** — read everything; write only its own prefix; the
explicit Deny beats any accidental broader Allow:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    { "Sid": "ReadAll",
      "Effect": "Allow",
      "Action": ["s3:GetObject", "s3:ListBucket"],
      "Resource": ["arn:aws:s3:::plantathome-media-prod",
                   "arn:aws:s3:::plantathome-media-prod/*"] },
    { "Sid": "WriteOwnPrefixOnly",
      "Effect": "Allow",
      "Action": ["s3:PutObject", "s3:DeleteObject"],
      "Resource": "arn:aws:s3:::plantathome-media-prod/media/s/*" },
    { "Sid": "NeverTouchProdMedia",
      "Effect": "Deny",
      "Action": ["s3:PutObject", "s3:DeleteObject"],
      "Resource": ["arn:aws:s3:::plantathome-media-prod/media/p/*",
                   "arn:aws:s3:::plantathome-media-prod/plants/*"] }
  ]
}
```

**plantathome-app-prod** — read all; write `media/p/*` plus the legacy prefixes
still fed by un-migrated paths (drop the Legacy statement at phase 6):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    { "Sid": "ReadAll",
      "Effect": "Allow",
      "Action": ["s3:GetObject", "s3:ListBucket"],
      "Resource": ["arn:aws:s3:::plantathome-media-prod",
                   "arn:aws:s3:::plantathome-media-prod/*"] },
    { "Sid": "WriteProdMedia",
      "Effect": "Allow",
      "Action": ["s3:PutObject", "s3:DeleteObject"],
      "Resource": "arn:aws:s3:::plantathome-media-prod/media/p/*" },
    { "Sid": "LegacyUntilPhase6",
      "Effect": "Allow",
      "Action": ["s3:PutObject", "s3:DeleteObject"],
      "Resource": ["arn:aws:s3:::plantathome-media-prod/plants/*",
                   "arn:aws:s3:::plantathome-media-prod/ai-batches/*",
                   "arn:aws:s3:::plantathome-media-prod/ai-instant/*"] }
  ]
}
```

Note: spatie-medialibrary keys (`{media.id}/file`) sit at the bucket **root**
and cannot be prefix-allowed without allowing `*` — the legacy attachment
uploader keeps working only until phase 6 retires it (see Risks).

## CloudFront setup

1. **ACM certificate in us-east-1** (CloudFront requirement, regardless of the
   bucket's ap-south-1) for `media.plantathome.in`.
2. Create the distribution: origin = the bucket via **Origin Access Control**
   (not legacy OAI), cache policy CachingOptimized (objects carry `immutable`
   anyway), alternate domain name + the ACM cert.
3. **Bucket policy**: add the CloudFront service-principal statement
   (`"Principal": {"Service": "cloudfront.amazonaws.com"}` with a
   `AWS:SourceArn` condition naming the distribution) **alongside** the existing
   public-read statement — public-read only comes off at phase 6, so nothing
   breaks while both paths serve.
4. **DNS**: CNAME `media.plantathome.in` → the distribution domain.
5. Verify: `curl -sI https://media.plantathome.in/<known-key>` → 200 with
   `x-cache: Miss from cloudfront`, again → `Hit from cloudfront`.
6. Set `AWS_URL=https://media.plantathome.in` (staging first), `config:cache`,
   `queue:restart`.

## Phased rollout

| phase | what happens | reversal |
|---|---|---|
| 0 | Deploy code + migrations. Nothing user-visible changes; legacy paths untouched. | revert deploy; migrations `down` (drops empty tables) |
| 1 | `media:adopt-existing` (staging, then prod via prod-data-op). DB-only backfill; S3 untouched. | `media:adopt-existing --rollback` |
| 2 | CloudFront live (steps above), `AWS_URL` set. **New** URLs serve via CDN; old stored URLs still raw-S3 (both work — same objects). | unset `AWS_URL` + `config:cache` |
| 3 | `media:rewrite-urls` — historic stored URLs → CDN base. | `media:rewrite-urls --rollback` (snapshots) |
| 4 | Admin flows move to `/media` endpoints: upload→review→publish, versioning, rollback. Legacy `attachments` upload stays as fallback. | point admin back at legacy upload; media rows persist harmlessly |
| 5 | IAM env-split policies attached; `media:gc` scheduled both envs. | detach policies; unschedule gc |
| 6 | Bucket goes private: public-read statement removed (CloudFront OAC only); legacy prefixes frozen (prod Legacy IAM statement dropped); spatie root-key uploader retired; after `media:audit-refs` green, `media:gc --include-adopted` may purge retired adopted keys. | re-add public-read statement (instant); restore Legacy IAM statement |

## Risks

- **Retention vs refresh cadence.** `MEDIA_RETIRED_RETENTION_DAYS` (30) must
  stay above the staging DB-refresh interval, or a stale staging DB can point at
  a purged prod object. Guard the number, not the habit — if refreshes ever
  slow to quarterly, raise retention first.
- **Spatie `{media.id}/file` keys are un-prefixable.** They live at bucket root,
  so no IAM prefix rule can cover them without `*`. Until phase 6 retires that
  uploader, root-level writes remain a code-guarded (not IAM-guarded) surface.
- **KYC documents are currently public objects.** Vendor KYC uploads ride the
  same public bucket. The media system doesn't fix this; the follow-up is
  signed CloudFront URLs (or a private prefix + signed S3 URLs) for
  `entity_hint=kyc` before any marketing of "private bucket" at phase 6.
- **Missed-column risk.** `media:rewrite-urls` and `media:audit-refs` share one
  registry (`RewriteMediaUrlsCommand::REGISTRY`). A media-URL column absent from
  it is invisible to **both** — the rewrite skips it and the audit still
  reports green. `media:audit-refs` gates `--include-adopted` and the CDN-only
  flip, so the registry must be extended whenever a new table stores media URLs;
  grep for the bucket host across the schema before phase 6.
- **Shared bucket, no versioning.** Every deletion is final. That is why gc is
  own-env-only, dry-runs first, adopted keys are default-skipped, and `purged_at`
  rows are kept as the audit trail.
