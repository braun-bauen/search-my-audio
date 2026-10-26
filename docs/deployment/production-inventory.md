# Stages 1–2 production evidence

Source: [execution plan](https://github.com/braun-bauen/search-my-audio/issues/121#issuecomment-6115966899), [risk decision](https://github.com/braun-bauen/search-my-audio/issues/126). This is an evidence ledger, not a claim that production gates have passed. Store actual identifiers, accounts, manifests, dumps, and screenshots in an operator-approved private location, outside Git and ephemeral app storage.

## Current evidence

Read-only Coolify MCP access is now available. SSH/container execution and database/storage data access remain unverified. No production backup has been captured or validated. No historical production access or operator-audio smoke test has run. Stages 1 and 2 remain gated; do not deploy this preparation until the blanks below are resolved and the operator approves the exact release.

| Fact | Evidence available | Required production evidence |
| --- | --- | --- |
| Hetzner region/SKU, capacity, charges | Plan supplies 4 vCPU / 8 GB / 40 GB, $24.99/month | Console/invoice; current SKU/region, IP/tax/backups and disk rescale constraints |
| Aggregate CPU/RAM/disk | Unmeasured | Host `free -m`, `df -h`, `du`, `vmstat`, process RSS, Docker stats including database/Coolify/build |
| Coolify version, app UUID, running revision | MCP: production app on main; latest finished deployment ce52bd3, Dockerfile /Dockerfile, port 80; recent deploys webhook-triggered | Console; deployed image digest/revision and branch; auto-deploy policy |
| Runtime limits / build location | MCP: application memory/CPU limits are 0 (unlimited); app shares Coolify host; concurrent builds=2 | Actual cgroup limits, Coolify limits, build host; these variables alone do not prove enforced limits |
| Process and stop commands | Deployed source: 12 database workers, PHP-FPM 18–50, nginx auto; proposed Docker config: 2 workers, FPM max 4, nginx 1 | Actual Supervisor config/status, process commands, job-level overrides; Coolify/Docker termination grace |
| Scheduler | No schedules in `routes/console.php` | MCP: no app scheduled tasks. Still inspect host/container cron, separate processes, cleanup and external schedules |
| Database | Checkout defaults SQLite | Actual engine/version/host/size, credentials location, transactional table engines, mounts, backup retention |
| Queue | Checkout default database, reservation 90s | Effective cached config; connection/table/name overrides, queue/batch/failed counts, oldest age, reservations and delayed work |
| Durable storage | MCP: no app persistent/file mounts; R2 and disk variable names present, values/effective paths unverified; `audioFiles/`, `transcriptions/`, `reports/` | Effective disk/roots/buckets/prefixes; every reference and all local durable files/mounts |
| Temporary storage | Livewire has separate disk; ingest reads default disk `livewire-tmp/` | Actual disk/prefix, backlog references, temp object listing/ages, cleanup and R2 lifecycle rules |
| Secrets | Environment variable names in checkout | Locations/access controls for original APP_KEY, DB, storage, provider, Flux, Stripe, Resend, Sentry; no values in Git/chat |
| Complete recurring cost | Only supplied VPS baseline | VPS/IP/tax/backups, R2 storage/operations/egress, email, monitoring and other subscriptions |
| Preservation / validation | Script and checklist prepared | Private manifest, readable complete backup, pre-existing missing objects, account checks, operator-owned audio and expected outcomes |

## Repository storage and job trace

`Search` → user, `files.search_id` → search. `files.audio_path` and `files.transcription_path`, plus `searches.report_path`, resolve through **the effective default disk**, not a fixed R2 disk. Preserve paths verbatim. `UploadFile` reads `livewire-tmp/<serialized path>` through the default disk and moves to `audioFiles/<path>`. Livewire writes through `LIVEWIRE_TMP_DISK`. Any mismatch stops release; do not silently switch disks or move historical objects.

Queued `BatchUpload` carries file names/temporary paths; `UploadFile` carries search/file metadata and batch ID; `ProcessFile` carries model references/retry flag/batch ID. Retain database jobs, batches, failed jobs, delayed/reserved jobs, serialized payloads, source files, APP_KEY and existing classes. Never deserialize queue payloads just to inventory them; retain hashes in private evidence and inspect selected payloads privately if needed. Also inventory any queue-specific DB connection, custom tables, additional disks, mounts, or prefixes not visible in this checkout.

`AudioFile::getTranscription()` can mutate status when a transcript is missing. The inventory script uses raw DB rows and storage streams instead. Historical UI checks may expose this existing behavior: snapshot first and record pre-existing missing objects separately. Do not run `app:update-file-status` or the historical status-reset migration indiscriminately.

## Coolify follow-up (2026-10-25 operator session)

Read-only MCP confirms the app is healthy, using Dockerfile builds on the same server as Coolify. No standalone database appears in this app project. No app mounts or scheduled tasks are configured. These facts do **not** prove the database or historical objects are absent: resolve the effective database connection and disk settings before any release. Host metrics are disabled; collect capacity and process RSS through operator terminal/SSH. The token cannot run a database backup or execute container commands. No production settings were changed.

Next operator inputs, in order:

1. A usable SSH hostname/user and existing key-based access, or an operator-driven Coolify Terminal session. The MCP server address `host.docker.internal` is internal, not a usable remote SSH target. Confirm auto-deploy behavior and actual container stop grace in Coolify without changing them yet.
2. Effective nonsecret runtime facts: database engine/version/location/name; queue connection/table/name/retry_after; default and Livewire disks/buckets/prefixes; database mount/backup owner if external. Retain passwords and APP_KEY in their existing secret store. Key names alone are insufficient; `DB_QUEUE_RETRY_AFTER` is absent from the app key listing, but shared/cached overrides still need checking.
3. A private durable backup/evidence destination with retention, plus database-native backup and R2 listing/read access (including lifecycle rules). Prefer existing server credentials over copying secrets. Capture and validate the backup before rollout.
4. Two operator-controlled test accounts, private historical sample IDs, and an operator-owned audio fixture with expected phrases/matches and an email recipient. Establish baseline before deploying.
5. Host capacity/RSS and backlog evidence, then approval of the exact commit, process limits, maintenance sequence and deployment. No merge, restart, migration or production configuration change before approval.
