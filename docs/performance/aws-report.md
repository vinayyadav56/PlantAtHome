# AWS / Infrastructure Report

> **MODELLED.** Costs are indicative ap-south-1 on-demand rates (730h/month) and exclude
> support plans, backups, NAT gateways, observability and data-transfer between AZs.
> Verify against the AWS calculator before committing budget.

## Where the platform runs today

| Component | Today | Problem |
|---|---|---|
| Storefront (Next.js) | **one PM2 fork process**, one EC2 box, behind Cloudflare | Single core serves all SSR *and* the `/rest-api` proxy. No failover. |
| Admin (Next.js) | one PM2 fork process, same box | Competes with the storefront for the same CPU |
| API (Laravel) | php-fpm on the same EC2 host, `api.plantathome.in` | `pm.max_children` not in the repo — read it off the box |
| Database | MySQL / RDS | Also serves the cache and the rate limiter |
| Cache | **`database`** | Redis is configured but has no client installed |
| Queue | `database`, 6 systemd workers | Same instance again |
| Static / media | S3 `plantathome-media-prod`, **not** behind CloudFront | 48 raw `<img>` serve unresized originals straight from S3 |
| Staging | Railway + Vercel | Shares the S3 bucket with production |

## Recommended target architecture

1. **ALB + Auto Scaling group** for the Next apps, with PM2 in cluster mode inside each
   instance. Today one process on one box is both the capacity ceiling and the single point
   of failure.
2. **CloudFront in front of the read API.** The API already emits
   `s-maxage=300, stale-while-revalidate=600` on public endpoints, so the caching contract
   exists and is simply unused. This is the largest single lever in the capacity model —
   at 100k users the app tier is 232 cores at 80% offload versus 555 at 0%.
3. **ElastiCache (Redis)** for cache, sessions and the rate limiter. Removes 13–15 MySQL
   round trips per request from the primary.
4. **RDS with read replicas.** Reads dominate; the model adds the first replica at 75k users.
5. **SQS** for the queue, so a worker backlog stops competing with the storefront for
   database connections.
6. **CloudWatch + X-Ray.** There is currently **no APM at all** — no New Relic, Datadog,
   Sentry, Telescope or Blackfire, and no slow-query log. Every number in this analysis had
   to be produced by building instrumentation first. That is the real gap.
7. **WAF** in front of the ALB, since the legacy `/api/*` tree deliberately carries no
   group-wide throttle.

## Modelled infrastructure

| Users | Origin RPS | vCPU | App instances | Database | Redis | TB/month | $/month |
|---:|---:|---:|---:|---|---:|---:|---:|
| 10,000 | 767 | 24 | 12 | r7g.large | 1 | 157.6 | $18,628 |
| 25,000 | 1,917 | 58 | 29 | r7g.2xlarge | 1 | 393.9 | $46,499 |
| 50,000 | 3,833 | 116 | 58 | r7g.2xlarge | 1 | 787.8 | $92,139 |
| 75,000 | 5,750 | 174 | 87 | r7g.2xlarge +1r | 1 | 1181.6 | $138,457 |
| 100,000 | 7,667 | 232 | 116 | r7g.2xlarge +1r | 1 | 1575.5 | $184,097 |
| 200,000 | 15,333 | 464 | 232 | r7g.2xlarge +3r | 1 | 3151.1 | $368,013 |
| 500,000 | 38,333 | 1,160 | 580 | r7g.2xlarge +9r | 1 | 7877.6 | $919,762 |
| 1,000,000 | 76,667 | 2,320 | 1,160 | r7g.2xlarge +19r | 1 | 15755.3 | $1,839,342 |

## Reading the 100k row honestly

7,667 origin RPS, 232 vCPU, $184,097/month —
but **5,000 RPS of that (65%) is the
7-second cart poll**, which carries a bearer token and is therefore uncacheable at every layer.

Fixing that one interval is worth more than any infrastructure line item here: it removes
roughly 65% of the app tier, taking the 100k figure from ~232 cores to ~81.

The sensitivity range across plausible assumptions is **162 to 1,361 cores**. Anyone quoting a
single number for "what 100k users costs" is guessing; the honest answer is a range with its
inputs stated.

## Cheapest wins first

| Action | Cost | Effect |
|---|---|---|
| `pm2 start -i max` | zero | Multiplies app throughput by the box's core count |
| Remove/lengthen the cart poll | dev time | ~65% less app tier at scale |
| CloudFront over the read API | ~$931/mo at 10k | Largest single modelled lever |
| ElastiCache `cache.t4g.micro` | ~$12/mo | Removes cache load from the primary database |
| Raise `pm.max_children` | zero | Staging runs the stock 5 |

Every one of these lands before spending on a bigger instance.
