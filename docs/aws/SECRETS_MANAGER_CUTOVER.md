# Third-party credentials in AWS Secrets Manager — IAM and cutover runbook

Account `648163408188`, region `ap-south-1`. Secret names are
`plantathome/{environment}/{provider_slug}` (e.g. `plantathome/production/razorpay`), created
lazily by the admin on the first save. The database keeps only the secret's name and version.

Identity is the SDK's default credential chain — there is deliberately no AWS key in this
repository, in config, or (after this cutover) in `.env` on the production box:

| Where | Identity | May touch |
|---|---|---|
| Production EC2 | instance profile `PlantAtHomeEC2SSM` | `plantathome/production/*`, `plantathome/sandbox/*`, the media bucket |
| Staging (Railway) | IAM user `plantathome-app-staging`, key in Railway variables | `plantathome/staging/*`, the media bucket (its existing scope) |
| A developer laptop | their own `~/.aws` profile, or `INTEGRATIONS_CREDENTIAL_STORE=database` | nothing production |

Environment separation is IAM, not convention: neither identity can even list the other's
secrets by name pattern. (`sandbox` is the production box's partner-UAT lane — Porter UAT and
Porter production must coexist during onboarding — so the production role covers both.)

## 1. IAM (one-off, from an admin identity — not the root key)

```bash
# production: extend the existing EC2 role
aws iam put-role-policy --role-name PlantAtHomeEC2SSM \
  --policy-name plantathome-secrets-and-media \
  --policy-document file://docs/aws/ec2-role-secrets-and-media.json

# staging: activate the user that was created scoped but never given a key
aws iam put-user-policy --user-name plantathome-app-staging \
  --policy-name plantathome-staging-secrets \
  --policy-document file://docs/aws/staging-user-secrets.json
aws iam create-access-key --user-name plantathome-app-staging   # copy once; it is never shown again
```

⚠️ The local CLI profile on the owner's machine is the **account root key**, and root access
keys exist. Run the above from an IAM admin user, then delete the root access key
(`aws iam delete-access-key` as root, after creating the admin user). Nothing in this module
needs root.

## 2. Staging first (Railway has no instance role, so the key must precede the deploy)

Railway injects real process env vars and `start.sh` runs `config:clear`, so the SDK's env
provider wins there. Order matters:

1. Dispatch `set-railway-s3-vars.yml` with the new key and:
   `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY` (the `plantathome-app-staging` key),
   `AWS_REGION=ap-south-1`, `INTEGRATIONS_CREDENTIAL_STORE=secrets_manager`,
   `INTEGRATIONS_ENVIRONMENT=staging`. Railway restarts the service on a variable change.
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
