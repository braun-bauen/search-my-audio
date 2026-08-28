# Laravel Cloud and low-cost hosting options

_Research date: 2026-08-27_

## Question

How should Search My Audio host its web application, queues, database, object storage, transactional email, and observability at the lowest practical cost while retaining a path to scale?

## Recommendation

Use **Laravel Cloud Starter as the intended production destination**, subject to a short proof-of-fit deployment that exercises the real upload and queue pipeline. Keep **Railway Hobby** as the fallback if Laravel Cloud cannot reliably wake and run the application's long audio jobs or if its Flex 2 GiB ceiling is insufficient after the worker is corrected.

This is not a recommendation to migrate immediately. The application currently starts 12 queue workers and 18 PHP-FPM workers in one container (`nixpacks.toml`), which is the dominant mismatch with small compute. First reduce concurrency and measure peak memory for one representative 25 MB upload. Hosting migration should follow that optimization, not mask it with a larger instance.

## Current workload shape

The repository currently needs:

- a Laravel 12 / Livewire web process;
- database-backed queues and a scheduler;
- bursty, outbound HTTP transcription jobs, each accepting an audio file up to 25 MB;
- durable relational data, uploaded audio, JSON transcripts, and CSV reports;
- low-volume completion email through Resend; and
- Sentry plus platform logs.

The production database engine and actual database/object sizes are not recorded in the repository. Those quantities must be measured before a final cost forecast or migration procedure is approved.

## Cost and operational comparison

Costs below exclude OpenAI transcription, Stripe fees, domain registration, and taxes because they do not materially change with the host. They assume low traffic, one production environment, no high availability, Resend's free tier, Sentry's free tier, and modest object storage.

| Option | Realistic quiet-app floor | Fit for this app | Important limitations |
| --- | ---: | --- | --- |
| **Laravel Cloud Starter** | **About $5/month** before storage/overages | Best overall fit. Starter is $5/month plus usage and includes $5 monthly usage credit, one managed queue per environment, task scheduling, scale-to-zero Flex compute, Serverless Postgres/MySQL, SSL, DDoS mitigation, and basic logs. Flex compute ranges from 512 MiB ($6 monthly cap) to 2 GiB ($24 cap) and is metered per second; Cloud says sleeping resources wake in under 500 ms. [Pricing](https://laravel.com/cloud/pricing) | Starter is limited to one replica and 2 GiB RAM; logs and metrics retain only one day. Scale-to-zero must be proven with queued work, and the current MySQL-to-serverless-Postgres tradeoff is a separate decision. Spending limits pause compute, so an overly tight limit trades cost safety for availability. |
| **Railway Hobby** | **$5/month minimum**, likely $5–10 at very low usage | Strong fallback. The $5 subscription includes the first $5 of resource usage; compute is metered by the minute at $10/GB-month RAM and $20/vCPU-month, volumes at $0.15/GB-month, and egress at $0.05/GB. Services can sleep. [Pricing](https://docs.railway.com/pricing) | Sleep is inferred from absence of **outbound packets** for 10 minutes; database connections and telemetry can prevent it. A polling queue worker therefore may not sleep naturally, making configuration and cost less predictable. [Serverless behavior](https://docs.railway.com/deployments/serverless) More platform assembly and Laravel-specific configuration remain with the maintainer. |
| **Render** | **Roughly $20+/month** for paid web + continuously running worker + durable database | Mature managed platform with Git deploys, private networking, managed Postgres, logs, and background-worker services. | Render documents background workers as continuously running. Its free web service sleeps after 15 minutes but takes about a minute to wake, loses local files on restart, cannot run one-off jobs, and its free Postgres expires after 30 days; Render explicitly says free instances are not for production. [Free-tier limits](https://render.com/docs/free), [background workers](https://render.com/docs/background-workers) The production topology therefore defeats the desired near-zero idle cost. |
| **Fly.io** | **About $6–15/month raw infrastructure** for small app and self-managed database/volume; more for managed data services | Cheap granular compute with Machines that can stop and start. A 512 MiB shared Machine is capped near $3.32/month and a 1 GiB Machine near $5.92/month. [Resource pricing](https://fly.io/docs/about/pricing/) | Stopped Machines still bill root filesystem usage; volumes cost $0.15/GB-month even while stopped. The cheapest database path is explicitly unsupported self-managed Postgres, restoring the backup, patching, recovery, and capacity work the migration is meant to remove. Managed Postgres and support raise the floor. |
| **Current VPS** | **$25/month fixed** | Known, fully controllable, and already working. | Pays for idle capacity and leaves OS, runtime, database, queue, backups, security, and scaling operations with the maintainer. Retaining it is only justified if profiling proves the workload cannot fit managed scale-to-zero constraints or needs host-level customization. |

The cost estimates are intentionally ranges, not quotes: database and object-storage quantities are unknown, and actual awake time is the main variable. Laravel Cloud itself shows a low-traffic example with web compute plus 0.5 GB Serverless Postgres costing about $0.39 of resource usage per month; its Starter subscription and included credit make the practical bill floor about $5 under the currently published plan. [Laravel Cloud pricing examples](https://laravel.com/cloud/pricing)

## Service-by-service shape on Laravel Cloud

### Web and queue compute

Start with separate logical web and worker processes at the smallest measured Flex sizes. Do not reproduce the current 12-worker Supervisor configuration. Set worker concurrency to one initially, use a visibility/retry timeout longer than the worst observed transcription request, cap retries, and prove idempotency before increasing concurrency.

Laravel Cloud Starter includes only one managed queue per environment and one replica. That is sufficient for the present workload if uploads, transcription, report generation, and email share a deliberately prioritized queue or use the database queue initially. If isolation between fast notification jobs and long transcription jobs becomes necessary, Growth's ten managed queues and worker clusters become the clean upgrade path, but its $20 base plan is not warranted yet. [Plan limits](https://laravel.com/cloud/pricing)

### Database

Prefer the engine that minimizes migration risk after inspecting production. Laravel Cloud supports MySQL Flex and Neon Serverless Postgres, and permits an externally hosted database over a public connection. [Database options](https://laravel.com/cloud/pricing) Do not select Postgres solely because it sleeps: first inventory MySQL-specific schema/query behavior and test a restored production snapshot. Storage remains billable while compute sleeps.

### Object storage

Uploaded audio, transcript JSON, report CSV, and Livewire temporary uploads cannot remain on ephemeral application storage. Laravel's migration guide requires local uploads to move to S3-compatible object storage and notes that an existing S3 bucket can remain in place with environment-variable changes. [Laravel migration guide](https://laravel.com/cloud/migrate-forge-cloud)

Either Laravel Object Storage or the already-configured Cloudflare R2 disk is viable. R2 is particularly attractive for a tiny app: its current free allowance is 10 GB-month, 1 million Class A operations, 10 million Class B operations, with free Internet egress. [R2 pricing](https://developers.cloudflare.com/r2/pricing/) Keeping R2 does add a service, but avoids coupling user audio to the compute host and may cost $0 at present scale. The final choice depends on measured stored GB and retention policy.

### Email and observability

Keep Resend rather than looking for a hosting-integrated mail product. Its free transactional tier includes 3,000 messages/month with a 100/day ceiling, comfortably aligned with the stated small user base. [Resend pricing](https://resend.com/docs/knowledge-base/what-is-resend-pricing)

Keep Sentry for application exceptions and traces. Laravel Cloud Starter's one-day log and metric retention is useful for immediate diagnosis but insufficient as the sole incident history. Cost alerts should be enabled in both Cloud and external services.

## Proof-of-fit gate before choosing the host

Run a temporary Laravel Cloud Starter environment with a sanitized copy or representative dataset and verify:

1. A sleeping web environment wakes, accepts an upload, commits durable state, and exposes accurate progress.
2. A queued job wakes processing without manual intervention, survives for the longest expected OpenAI request, and reliably triggers the completion email.
3. Retry, timeout, duplicate-delivery, and worker-restart tests leave one coherent result and no orphaned status.
4. A single worker's peak RSS fits the selected Flex size with headroom; repeat with two workers only if measurements justify it.
5. Database restore, object download, temporary upload handling, scheduled cleanup, logs, Sentry reporting, and rollback all work.
6. One week of representative usage is extrapolated into low, expected, and burst monthly cost scenarios.

Choose Railway instead only if this test exposes an unworkable Laravel Cloud queue-wake/runtime constraint and the same test succeeds on Railway. Keep the VPS until the managed-host deployment passes the production smoke test and the short maintenance-window data cutover has a verified rollback.

## Decision-relevant conclusion

Laravel Cloud should remain the intended destination: it offers the same practical ~$5 quiet-app floor as Railway with materially less Laravel-specific operations and a clearer scale path. The decision must remain conditional on profiling away the current extreme process concurrency and proving reliable scale-to-zero queue completion; Railway is the fallback, while Render, Fly.io, and the current VPS do not beat that cost/management balance for this workload.

