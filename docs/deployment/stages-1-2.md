# Existing-server preservation and bounded deployment

Implements preparation for [rollout stages 1–2](https://github.com/braun-bauen/search-my-audio/issues/121#issuecomment-6115966899). The [production evidence ledger](production-inventory.md) is the release gate. No framework/provider/hosting change, new schema, historical rewrite, cleanup policy, bulk retry, or retranscription is included.

## 1. Inventory and preserve before approval

Obtain SSH/Coolify read access, storage listing/read access, database-native backup access, private evidence destination, two operator-controlled accounts (owner/other user), and operator-owned audio with known matches/control phrases. 1Password CLI is optional: configure an account/session with the operator and reference secrets by item; never put tokens or passwords in command history, chat, PRs or logs. Record secret locations, not values.

Inspect the deployed revision and all overrides before proposing final limits. Safe host reads include `free -m`, `df -h`, `vmstat 1 10`, `docker ps`, `docker stats --no-stream`, and `ps -eo pid,ppid,comm,rss,%cpu`. Use targeted `docker inspect --format` for mounts, image, resource limits and stop timeout; do not dump full inspect/environment output into logs. Inspect Supervisor/cron privately. Inventory actual storage via console/API, including bucket lifecycle rules, local durable/temp directories, and uploads pending in queued payloads. No cleanup or lifecycle edits.

Run the standalone script with the **current** release's vendor/bootstrap and effective cached config, before deploying code. Copy the reviewed script to a private operator directory; this does not require deploying the new release:

```sh
umask 077
php /private/tools/preservation-inventory.php /app /private/evidence/before.jsonl
```

The destination must be a mounted/private durable location outside `/app`, with a secure copy off ephemeral container storage. The JSONL contains private IDs, billing identifiers and object keys, row hashes and transcript hashes, not queries/transcripts/passwords/payloads. Confirm the final `complete` record. An incomplete capture fails the gate. Capture custom queue DB/tables, additional storage disks/prefixes and local mounts separately. Hashes cannot replace a full database backup. For comparable before/after row hashes, record the capture window and take the final captures while writes/workers are quiescent; changing queue/session rows during a live capture are not evidence of corruption.

### Native database backup

Choose the engine from production, not `.env.example`. Capture **all schema/data**, including users/auth, billing/subscriptions, migrations, jobs/batches/failed jobs and sequences. Use private credential files/native authentication; do not print secrets. Record native client/server versions, start/end time, exit status, size, SHA-256, table counts and backup location/retention. Copy off the app container and confirm the copied checksum. Preserve any genuinely local durable and pending-upload files privately too; a DB dump does not preserve them.

- PostgreSQL: `pg_dump --format=custom --file=/private/evidence/pre-change.dump` using a protected `PGPASSFILE` and the confirmed connection. Require successful exit and `pg_restore --list`. Validate by importing with `pg_restore --exit-on-error` into an isolated disposable database with production-matching engine/version, then compare schema/table counts. Never target production for validation.
- MySQL/MariaDB: native `mysqldump`/`mariadb-dump` using a protected `--defaults-extra-file`, `--single-transaction --routines --events --triggers --hex-blob` and the confirmed database name. Confirm all tables are transactional or coordinate a consistent backup/lock during approved maintenance. Require successful exit, a nonempty export, import into an isolated disposable same-engine database, and schema/count comparison. Do not treat a header/footer or gzip integrity alone as readable/complete data.
- SQLite: use native `sqlite3` `.backup` against the actual file, rather than copying a live file without WAL state. On the backup run `PRAGMA integrity_check` (must return `ok`), `.schema`, and table counts. Confirm sidecar/mount configuration privately.

These are basic backup readability/completeness checks, not a dedicated recovery rehearsal. The exact engine-specific commands remain gated on actual connection/engine details. An unreadable backup or unknown object placement stops release preparation. Refresh the backup after approved maintenance if writes continued since capture; never overwrite new user writes with an older snapshot.

### Historical access checks

Record private sample IDs and results, without content/screenshots in the PR. Establish before/after row counts, ownership/foreign keys, timestamps, statuses, match counts, billing identifiers and referenced keys/checksums. Every owned historical result, transcript, audio and report reference must be accounted for; baseline missing objects must be explicitly listed and must not increase.

Use the owner's existing login; check verified/unverified behavior, subscribed/unsubscribed entitlement behavior, history, direct historical results, transcript display/copy, and report download. Check actual audio accessibility through its existing storage/access contract (the current UI has no audio-download route). Use a different user to request the owner's result ID and attempt report/transcript actions; expect authorization denial and no content. Check guests are redirected/denied. Verify billing portal/entitlements without creating/cancelling subscriptions or sending customer mail. Do not delete historical searches, retry successful files or rewrite transcripts during checks. Stop and fix any failed access gate before deployment.

## 2. Review and obtain operator approval

Review the exact commit, production evidence, backup validation, overrides, memory-derived FPM ceiling, deployment stop grace, backlog queues/batches, maintenance plan and operator-audio fixture. **Obtain the user's approval before any production deployment, environment/config change, worker stop/restart, maintenance action or migration.** PR creation/merge must not accidentally auto-deploy: verify Coolify's tracked branch and auto-deploy settings before merging. Do not merge or deploy this PR while gates are unresolved.

Prepared configuration:

| Component | Proposed setting | Production gate |
| --- | --- | --- |
| Transcription + legacy queue | One database worker, `transcription,default` | Replace `default` with actual legacy queue name if overridden; no other transcription consumers |
| Ingest / report / email | One worker, `lightweight,ingest` | New upload batches contain ingest only; prioritize report/email outside transcription |
| Provider | connect 10s, request 600s | Same Whisper model, same input/storage contract; validate supported long input |
| Worker | 720s on both workers and ProcessFile | pcntl available; confirm no old payload timeout overrides |
| DB reservation | 900s | Explicit `DB_QUEUE_RETRY_AFTER=900`; cached config and any custom queue DB confirmed |
| Supervisor shutdown | 900s | Coolify/Docker/orchestrator must permit at least 900s too |
| PHP-FPM / nginx | ondemand, max 4 children / 1 nginx process | Provisional: derive final children from measured peak per-process RSS and actual RAM budget |

Compute FPM children as floor((container RAM budget minus peak worker/nginx/other fixed usage and chosen headroom) / peak observed FPM RSS); include database/Coolify/build usage at host level. Four children is a preparation ceiling, not a measured production fit. Adjust the reviewed ceiling before approval if evidence requires it. Do not downsize or impose smaller upload limits.

The 900-second reservation precedes a healthy 720-second worker timeout only after the attempt has ended, avoiding normal premature redelivery. Supervisor grace alone cannot prevent Docker killing a healthy attempt. The stage-4 lease/reconciler is **not implemented** here; do not claim the future 20-minute recovery contract is proven. Existing retry/finalization idempotency and no-match email gaps remain stage-4 work; do not induce crash/duplicate delivery on customer jobs to measure this release.

New `BatchUpload`/upload batches use `ingest`; an uploaded file dispatches a standalone `ProcessFile` to `transcription`. Its nullable batch guard also supports existing manual retries. Existing serialized classes/properties remain readable. Previously batched ProcessFile payloads on the legacy queue retain their batch cancellation guard. New batch completion now describes ingest completion only; search completion still comes from file states. Legacy default jobs are consumed by the single transcription worker; they may still queue behind existing transcription until drained, but must not be moved/deleted/replayed. Confirm new ingestion/report progress while that worker is busy.

### Approved maintenance sequence

1. Close submissions with the approved maintenance mechanism; do not clear uploads or queues. Record jobs by queue/class, reserved/delayed counts, batch IDs and temp objects privately.
2. Prevent old worker respawn through the operator's process manager; gracefully stop old workers and wait for active attempts to finish. Verify every old consumer is gone before starting any new consumers. Do not use queue clearing, bulk retry, `migrate:fresh`, or forced container removal. Record any pre-existing interrupted reservation for private investigation; do not reset it blindly.
3. Take/validate the final private backup and preservation manifest while writes are quiescent. Build the image with no DB migration hook. Disable any Coolify build/deploy migration command override. Keep new workers stopped until checks finish.
4. Review `php artisan migrate:status` and each pending migration. This PR adds none. The existing historical `2025_05_17_025637_reset_file_status` destroys status values; unexpected pending migrations stop this release. Only explicitly reviewed migrations may run with `php artisan migrate --force` after worker coordination/backup. Never add migration execution back to the build.
5. Set confirmed reservation/configuration; regenerate runtime configuration cache with actual secrets available. Verify `services.openai.key` resolves privately without printing it. Preserve APP_KEY/storage/DB/queue settings. `php artisan optimize` remains in build; ensure deployment refreshes its caches when runtime values differ. Check Supervisor commands, FPM config, pcntl, actual reservation and outer shutdown grace. Verify historical access and no new missing references.
6. Start exactly the two proposed workers; allow legacy queued work to continue. With operator-owned audio only, run one complete search with positive matches, report and completion email, plus a control search. Inspect expected matches manually and verify no query/transcript/audio/provider body appears in app/proxy/Sentry logs. Record output privately. Do not use customer audio as a fixture.
7. Record baseline measurements below, compare preservation checks, reopen only when gates pass. If anything fails, keep submissions/processing paused and preserve all records/source objects. Revert only a schema/payload-compatible release, coordinating workers again; do not restore a snapshot over new writes. Jobs newly queued on `ingest`/`transcription`/`lightweight` must remain serviced by compatible single-transcription and lightweight workers even during a code rollback.

### Baseline measurements and checks

Before/after ordinary page latency (same authenticated route, multiple samples), peak per-process RSS and aggregate container/host RAM, CPU, disk growth/I/O, DB connections/contention/slow queries (without SQL bindings), queue wait/duration/attempts by stage, provider duration/status, report completion and actual email receipt. Use `docker stats`, `ps`, host monitoring and native DB aggregate metrics throughout a full approved-audio run plus simultaneous browsing; record timestamps, release and input size/format privately. Do not run later hosting qualification or purchase capacity here.

`pipeline_job` logs record queue, class, job ID, attempts, execution duration, and PHP peak memory; `pipeline_provider` logs duration/status and file/search IDs only. For database queue wait, privately query each queue's `created_at`/`available_at`/`reserved_at` and oldest eligible age, never payload/query fields. PHP peak memory is not process RSS; use OS monitoring too. Hard-killed attempts have a start without an end; correlate Supervisor restart/OOM/cgroup events. Alert/review repeated ordinary queue waits over one hour; do not increase concurrency automatically.

Local/CI checks: `php artisan test`, `vendor/bin/pint --test` on changed PHP files, `npm ci && npm run build`, parse the Nixpacks TOML, inspect all generated Supervisor configs and PHP-FPM syntax in the actual image. Production gates: historical access, complete readable backup, successful approved-audio search/report/email, no OOM/restarts, no premature reservation expiry or forced truncation of healthy attempts. Record these in the evidence ledger; absent facts are not passing checks.

Technical references: [Laravel 12 queue implementation](https://github.com/laravel/framework/tree/12.x/src/Illuminate/Queue), [Laravel 12 HTTP client API](https://api.laravel.com/docs/12.x/Illuminate/Http/Client/PendingRequest.html). Stop after this bounded-server baseline: framework/UI upgrade, transcriber migration, durable orchestration and hosting selection are separate releases.
