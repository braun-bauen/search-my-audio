# Stages 1–2 production evidence

Source: [execution plan](https://github.com/braun-bauen/search-my-audio/issues/121#issuecomment-6115966899), [risk decision](https://github.com/braun-bauen/search-my-audio/issues/126). This is an evidence ledger, not a claim that production gates have passed. Store actual identifiers, accounts, manifests, dumps, and screenshots in an operator-approved private location, outside Git and ephemeral app storage.

## Current evidence

Production access has not been supplied. The local `op` CLI has no configured account; no production SSH target or Coolify session is available. No production backup has been captured or validated. No historical production access or operator-audio smoke test has run. Stages 1 and 2 remain gated; do not deploy this preparation until the blanks below are resolved and the operator approves the exact release.

| Fact | Evidence available | Required production evidence |
| --- | --- | --- |
| Hetzner region/SKU, capacity, charges | Plan supplies 4 vCPU / 8 GB / 40 GB, $24.99/month | Console/invoice; current SKU/region, IP/tax/backups and disk rescale constraints |
| Aggregate CPU/RAM/disk | Unmeasured | Host `free -m`, `df -h`, `du`, `vmstat`, process RSS, Docker stats including database/Coolify/build |
| Coolify version, app UUID, running revision | Unknown | Console; deployed image digest/revision and branch; auto-deploy policy |
| Runtime limits / build location | `nixpacks.toml` declares 2g/1.5 variables | Actual cgroup limits, Coolify limits, build host; these variables alone do not prove enforced limits |
| Process and stop commands | Checkout: 12 database workers, PHP-FPM 18–50, nginx 5 | Actual Supervisor config/status, process commands, job-level overrides; Coolify/Docker termination grace |
| Scheduler | No schedules in `routes/console.php` | Host/container cron, separately supervised processes, cleanup and external schedules |
| Database | Checkout defaults SQLite | Actual engine/version/host/size, credentials location, transactional table engines, mounts, backup retention |
| Queue | Checkout default database, reservation 90s | Effective cached config; connection/table/name overrides, queue/batch/failed counts, oldest age, reservations and delayed work |
| Durable storage | Default disk; `audioFiles/`, `transcriptions/`, `reports/` | Effective disk/roots/buckets/prefixes; every reference and all local durable files/mounts |
| Temporary storage | Livewire has separate disk; ingest reads default disk `livewire-tmp/` | Actual disk/prefix, backlog references, temp object listing/ages, cleanup and R2 lifecycle rules |
| Secrets | Environment variable names in checkout | Locations/access controls for original APP_KEY, DB, storage, provider, Flux, Stripe, Resend, Sentry; no values in Git/chat |
| Complete recurring cost | Only supplied VPS baseline | VPS/IP/tax/backups, R2 storage/operations/egress, email, monitoring and other subscriptions |
| Preservation / validation | Script and checklist prepared | Private manifest, readable complete backup, pre-existing missing objects, account checks, operator-owned audio and expected outcomes |

## Repository storage and job trace

`Search` → user, `files.search_id` → search. `files.audio_path` and `files.transcription_path`, plus `searches.report_path`, resolve through **the effective default disk**, not a fixed R2 disk. Preserve paths verbatim. `UploadFile` reads `livewire-tmp/<serialized path>` through the default disk and moves to `audioFiles/<path>`. Livewire writes through `LIVEWIRE_TMP_DISK`. Any mismatch stops release; do not silently switch disks or move historical objects.

Queued `BatchUpload` carries file names/temporary paths; `UploadFile` carries search/file metadata and batch ID; `ProcessFile` carries model references/retry flag/batch ID. Retain database jobs, batches, failed jobs, delayed/reserved jobs, serialized payloads, source files, APP_KEY and existing classes. Never deserialize queue payloads just to inventory them; retain hashes in private evidence and inspect selected payloads privately if needed. Also inventory any queue-specific DB connection, custom tables, additional disks, mounts, or prefixes not visible in this checkout.

`AudioFile::getTranscription()` can mutate status when a transcript is missing. The inventory script uses raw DB rows and storage streams instead. Historical UI checks may expose this existing behavior: snapshot first and record pre-existing missing objects separately. Do not run `app:update-file-status` or the historical status-reset migration indiscriminately.
