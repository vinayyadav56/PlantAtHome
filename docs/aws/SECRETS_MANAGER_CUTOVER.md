# Third-party credentials in AWS Secrets Manager — IAM and cutover runbook

Account `648163408188`, region `ap-south-1`. Secret names are
`plantathome/{environment}/{provider_slug}` (e.g. `plantathome/production/razorpay`), created
lazily by the admin on the first save. The database keeps only the secret's name and version.

Identity is the SDK's default credential chain — there is deliberately no AWS key in this
repository, in config, or (after this cutover) in `.env` on the production box:

| Where | Identity | May touch |
|---|---|---|
| Production EC2 | instance profile `PlantAtHomeEC2SSM` | `plantathome/production/*`, `plantathome/sandbox/*`, the media bucket |
| Staging (Railway) | IAM user `plantathome-s3-app` (the key it already uses) | `plantathome/staging/*`, the media bucket |
| A developer laptop | their own `~/.aws` profile, or `INTEGRATIONS_CREDENTIAL_STORE=database` | nothing production |

Administration is IAM user `pah-admin` (AdministratorAccess). The account root access key is not
used by anything here and should be deleted once this is complete.

Environment separation is IAM, not convention: neither identity can even list the other's
secrets by name pattern. (`sandbox` is the production box's partner-UAT lane — Porter UAT and
Porter production must coexist during onboarding — so the production role covers both.)

## 1. IAM — DONE 2026-09-22 (applied as `pah-admin`, verified by policy simulation)

```bash
# production: the EC2 role gains Secrets Manager (production/* + sandbox/*) and the media bucket
aws iam put-role-policy --role-name PlantAtHomeEC2SSM --profile pah-admin \
  --policy-name plantathome-secrets-and-media \
  --policy-document file://docs/aws/ec2-role-secrets-and-media.json

# staging: the key staging ALREADY uses gains staging/* secrets, and nothing in production
aws iam put-user-policy --user-name plantathome-s3-app --profile pah-admin \
  --policy-name plantathome-staging-secrets \
  --policy-document file://docs/aws/staging-user-secrets.json
```

Verified with `aws iam simulate-principal-policy` against a realistically suffixed ARN
(`…:secret:plantathome/production/razorpay-Ab3xYz` — Secrets Manager appends six random
characters, so the policies' trailing `/*` is load-bearing):

| Principal | production/* | staging/* | media bucket |
|---|---|---|---|
| `PlantAtHomeEC2SSM` | allowed | **implicitDeny** | allowed (incl. `s3:ListBucket` for the probe) |
| `plantathome-s3-app` | **implicitDeny** | allowed | unchanged |

### Why staging is not on `plantathome-app-staging`

That user exists and now carries the correct SECRET policy, but its S3 write scope is
`media/s/*` only — and the live upload path is spatie media-library's `DefaultPathGenerator`,
which writes bare `{media_id}/…` keys. The bucket's newest objects are `2449/…`, `2448/…`;
`media/s/` has never been written to at all. One credential set serves every AWS call in a
process, so pointing staging at that user would have broken every admin image upload there.
The access key minted for it during this work was deleted unused. See §6.

⚠️ The owner's local default CLI profile is the **account root key**, and root access keys
exist. Everything above ran as `pah-admin`; delete the root key when the cutover is done.

## 2. Staging first (Railway has no instance role, so the key must precede the deploy)

Railway injects real process env vars and `start.sh` runs `config:clear`, so the SDK's env
provider wins there. Order matters:

1. Dispatch `set-railway-s3-vars.yml` (`credential_store=secrets_manager`,
   `integrations_environment=staging`). It sets `AWS_REGION`,
   `INTEGRATIONS_CREDENTIAL_STORE` and `INTEGRATIONS_ENVIRONMENT`, and deliberately leaves
   `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` alone. Railway restarts on a variable change.
2. Merge `main` → `staging` (auto-deploys; runs migrations).
3. `php artisan integrations:migrate-credentials --environment=staging --relabel=sandbox:staging`
   (rows on staging were labelled `sandbox` before; the relabel is refused for any slug that
   already has a `staging` row).
4. Settings → Integrations shows `store: AWS Secrets Manager`; Test Connection on each enabled
   provider → connected; `aws secretsmanager list-secrets --filters Key=name,Values=plantathome/staging/`.
5. `... --environment=staging --purge` once step 4 is green.

## 3. Production

On the production box every deploy runs `config:cache`, and Laravel then never reads `.env`
into the process environment — so the SDK cannot see the static S3 key that is still in that
file and falls straight through to the instance profile. That is why the policy can be attached
first and the key removed last, with the site running throughout.

1. Attach the role policy (§1). Verify from the box:
   `aws sts get-caller-identity` → the `PlantAtHomeEC2SSM` role.
2. Deploy `main` (`API – Production Deploy`, `branch=main`, `confirm=deploy-production`).
   `production.yml` now writes `APP_ENV`, `AWS_REGION`, `INTEGRATIONS_CREDENTIAL_STORE` and
   `INTEGRATIONS_ENVIRONMENT`, and no longer writes the AWS access key.
3. Dispatch `cutover-secrets-manager.yml` with `purge=false`: removes the stale key lines from
   `.env`, rebuilds the config cache, restarts the workers, migrates and verifies every row.
4. `php artisan integrations:health` (or wait for the hourly sweep) — every enabled provider
   `connected`; Settings → Integrations → AWS S3 → Test Connection → connected **through the
   role**; upload an image from the admin.
5. Dispatch `cutover-secrets-manager.yml` with `purge=true`. This is the one-way step.
6. `aws iam update-access-key --user-name plantathome-s3-app --access-key-id … --status Inactive`.
   Delete the user a week later if nothing has complained.

## 4. Rollback

Before purge: set `INTEGRATIONS_CREDENTIAL_STORE=database`, `php artisan config:cache`,
`php artisan queue:restart`. The column is intact and reads resume from it.
After purge: re-enter the credential in Settings → Integrations (Secrets Manager also keeps the
previous version under the `AWSPREVIOUS` stage). There is deliberately no path that copies a
secret back into MySQL.

## 5. What the cutover does NOT change

- Everything still in `.env` (values never saved through the admin) keeps working through the
  env fallback and shows as **from environment — migrate** until re-entered. Re-entering is
  also the rotation the Borzo and Shiprocket tokens are owed.
- The Go shipping service keeps receiving sealed pushes from Laravel; it never reads Secrets
  Manager itself (it runs on Railway with no role).
- Scheduled commands fork a fresh process every minute and need nothing; queue workers are
  signalled to restart on every credential change.

## 6. Follow-up: give staging its own identity

Staging currently shares `plantathome-s3-app` with production (until §3 completes, after which
only staging uses it). To move staging onto `plantathome-app-staging`, its S3 write scope has to
match what the app actually writes:

```json
{ "Sid": "WriteMediaExceptProduction", "Effect": "Allow",
  "Action": ["s3:PutObject", "s3:DeleteObject"],
  "Resource": "arn:aws:s3:::plantathome-media-prod/*" }
```
keeping the existing `NeverTouchProdMedia` deny (`media/p/*`, `plants/*`, `backups/*`). That is
*stronger* isolation than today, because the shared key can currently write production media and
backups.

Before switching, confirm `config('media.env')` resolves to `staging` on the Railway service —
it derives from `RAILWAY_PUBLIC_DOMAIN` being set (`config/media.php`), and if it resolved to
`production` the new media system would target `media/p/` and be denied. Then mint a key for the
user, set it on Railway, and upload an image from the staging admin before trusting it.