# Current processing pipeline resource risks

Research ticket: [Measure the current processing pipeline's resource risks](https://github.com/braun-bauen/search-my-audio/issues/126)  
Repository baseline: [`09de9bd`](https://github.com/braun-bauen/search-my-audio/tree/09de9bd6dbb4e7e4e7836e0030912fbf9c8309d3)  
Measured: 2026-08-27

## Decision-relevant answer

The first architecture work should make processing idempotent and explicitly bounded, not begin with speculative query tuning. The dominant demonstrated risks are **12 concurrent long-running external API jobs in the same 2 GiB container as an aggressively sized PHP-FPM pool**, a queue visibility/timeout window that is too short for transcription, and terminal paths that can duplicate paid transcription, totals, reports, or email—or leave a search permanently unfinished. UI polling and avoidable full-collection/storage reads are the next tier. Existing relational list queries have index-shape improvements available, but the repository contains no evidence that they explain the VPS crashes.

## Scope and method

This is a static audit of the repository's jobs, models, migrations, deployment configuration, and Livewire views, checked against the Laravel and Livewire documentation. I also derived concurrency and request-rate bounds directly from committed settings. I could not run PHP tests, query plans, production telemetry, or heap profiles because this worktree has neither PHP nor installed Composer dependencies, and no production database or monitoring data was provided. Consequently, the numbers below are configuration bounds, not claims about observed production utilization.

## Highest-risk findings

### 1. Worker and web concurrency are grossly oversized relative to the declared container

The deployment declares 2 GiB and 1.5 CPUs, but Supervisor starts **12 queue worker processes**; PHP-FPM starts **18 servers** and may grow to **50**, while nginx starts five workers ([`nixpacks.toml`, lines 1–3 and 86–127](https://github.com/braun-bauen/search-my-audio/blob/09de9bd6dbb4e7e4e7836e0030912fbf9c8309d3/nixpacks.toml#L1-L3)). At startup that is at least 30 PHP processes (12 queue + 18 FPM) competing for 1.5 CPUs, before counting Supervisor/nginx. Laravel's worker default memory setting is 128 MiB per process, but this is a restart threshold checked between jobs rather than a container-wide reservation; twelve workers at that threshold alone represent 1.5 GiB of aggregate resident memory before the web pool and OS ([Laravel `WorkerOptions`](https://api.laravel.com/docs/12.x/Illuminate/Queue/WorkerOptions.html)).

Each processing job may stream an audio file up to 25 MiB to OpenAI ([`UploadFile.php`, lines 19–21](https://github.com/braun-bauen/search-my-audio/blob/09de9bd6dbb4e7e4e7836e0030912fbf9c8309d3/app/Jobs/UploadFile.php#L19-L21)) and holds the returned transcription, a segmented representation, and an encoded JSON representation during processing ([`ProcessFile.php`, lines 104–123 and 189–247](https://github.com/braun-bauen/search-my-audio/blob/09de9bd6dbb4e7e4e7836e0030912fbf9c8309d3/app/Jobs/ProcessFile.php#L104-L123)). At the configured concurrency, up to **300 MiB of source audio can be in flight** and twelve response/segmentation pipelines can coexist. That is not proof all 300 MiB is resident—the upload uses a stream—but it is a hard bound on simultaneous external transfer volume and a strong reason to profile per-job peak RSS before choosing concurrency.

**Architecture implication:** isolate web and transcription workers, start transcription concurrency at one, and scale it from measured queue age and per-job peak RSS. The present 12-process setting should not be carried to any low-cost target.

### 2. The queue timing contract permits expensive retranscription and duplicate completion work

The database queue releases a reserved job after 90 seconds ([`config/queue.php`, lines 37–44](https://github.com/braun-bauen/search-my-audio/blob/09de9bd6dbb4e7e4e7836e0030912fbf9c8309d3/config/queue.php#L37-L44)); the worker command supplies no `--timeout`, so Laravel's default is 60 seconds, and jobs get three attempts with no backoff ([`nixpacks.toml`, lines 86–99](https://github.com/braun-bauen/search-my-audio/blob/09de9bd6dbb4e7e4e7836e0030912fbf9c8309d3/nixpacks.toml#L86-L99)). Laravel states that worker timeout defaults to 60 seconds, `retry_after` should cover the longest reasonable job, timeout must be shorter than `retry_after`, I/O APIs need their own timeouts, and exception retries have zero backoff unless configured ([Laravel queues: timeouts, expiration, and retries](https://laravel.com/docs/12.x/queues#job-expirations-and-timeouts)).

`ProcessFile` sets no job timeout/backoff and the OpenAI HTTP request sets neither connection nor request timeout ([`ProcessFile.php`, lines 140–179](https://github.com/braun-bauen/search-my-audio/blob/09de9bd6dbb4e7e4e7836e0030912fbf9c8309d3/app/Jobs/ProcessFile.php#L140-L179)). Any transcription exceeding 60 seconds can kill a worker and be attempted up to three times. Because the external request and subsequent writes are not guarded by an idempotency state/lease, a timeout or crash can repeat paid transcription and later mutations. Twelve workers amplify that failure mode.

**Architecture implication:** define separate queues and per-job budgets, make `retry_after` safely exceed the job timeout, add HTTP connect/request timeouts and backoff, and establish an idempotency key/state transition before increasing concurrency.

### 3. Completion and retry transitions are race-prone and incomplete

Every successful file independently:

1. saves its result;
2. increments the parent total;
3. checks whether any `queued`/`uploaded` files remain;
4. may dispatch a report; and
5. marks the search completed

([`ProcessFile.php`, lines 57–75](https://github.com/braun-bauen/search-my-audio/blob/09de9bd6dbb4e7e4e7836e0030912fbf9c8309d3/app/Jobs/ProcessFile.php#L57-L75), [`Search.php`, lines 98–145](https://github.com/braun-bauen/search-my-audio/blob/09de9bd6dbb4e7e4e7836e0030912fbf9c8309d3/app/Models/Search.php#L98-L145)). There is no transaction, compare-and-set completion transition, unique job, or batch completion callback. Parallel last jobs can both observe “no pending files” and dispatch duplicate reports. `addToQueryCount` uses up to three writes/reads per file (increment or initialize, refresh, then a redundant save), increasing database contention.

The manual retry clears the transcription path but not the old `query_count`, then dispatches `ProcessFile` outside a batch ([`file-results.blade.php`, lines 38–51](https://github.com/braun-bauen/search-my-audio/blob/09de9bd6dbb4e7e4e7836e0030912fbf9c8309d3/resources/views/livewire/file-results.blade.php#L38-L51)). `ProcessFile` unconditionally calls `$this->batch()->cancelled()` ([`ProcessFile.php`, lines 39–44](https://github.com/braun-bauen/search-my-audio/blob/09de9bd6dbb4e7e4e7836e0030912fbf9c8309d3/app/Jobs/ProcessFile.php#L39-L44)), although Laravel documents `batch()` as retrieving the batch to which the job belongs and only permits adding jobs from within that same batch ([Laravel job batching](https://laravel.com/docs/12.x/queues#job-batching)). Thus the UI retry has no associated batch and plausibly fails before transcription; if that guard were bypassed, it would add the new match count to the previous total instead of replacing the old contribution.

**Architecture implication:** make file state the source of truth, recompute or transactionally adjust aggregates, and let one idempotent search-finalizer own terminal status/report/email. Test concurrent last-file completion and retry explicitly.

### 4. Several failure paths silently strand state or turn one failure into repeated work

- A missing or invalid upload logs and returns without creating a terminal file record or finalizing the search ([`UploadFile.php`, lines 38–81](https://github.com/braun-bauen/search-my-audio/blob/09de9bd6dbb4e7e4e7836e0030912fbf9c8309d3/app/Jobs/UploadFile.php#L38-L81)).
- A failed storage move is swallowed, after which a file record and processing job are still created for the destination ([`UploadFile.php`, lines 86–111](https://github.com/braun-bauen/search-my-audio/blob/09de9bd6dbb4e7e4e7836e0030912fbf9c8309d3/app/Jobs/UploadFile.php#L86-L111)).
- `ProcessFile::failed()` marks the file failed but never asks the search finalizer to run, so when the last outstanding file fails, no successful sibling remains to complete the parent ([`ProcessFile.php`, lines 72–95](https://github.com/braun-bauen/search-my-audio/blob/09de9bd6dbb4e7e4e7836e0030912fbf9c8309d3/app/Jobs/ProcessFile.php#L72-L95)). `allowFailures()` prevents batch cancellation but has no failure/completion callback here; Laravel describes those as separate behaviors ([Laravel batch failures](https://laravel.com/docs/12.x/queues#batch-failures)).
- `Storage::put()` in transcription ignores its boolean result while every configured disk uses `throw => false`; Laravel documents that failed writes return `false` in this mode ([`ProcessFile.php`, lines 117–129](https://github.com/braun-bauen/search-my-audio/blob/09de9bd6dbb4e7e4e7836e0030912fbf9c8309d3/app/Jobs/ProcessFile.php#L117-L129), [`config/filesystems.php`, lines 31–73](https://github.com/braun-bauen/search-my-audio/blob/09de9bd6dbb4e7e4e7836e0030912fbf9c8309d3/config/filesystems.php#L31-L73), [Laravel failed writes](https://laravel.com/docs/filesystem#failed-writes)). A file may therefore be marked transcribed with a nonexistent JSON object.
- `CreateReport` catches report generation errors, sends email inside the catch, then uses an undefined `$path` and sends again after the catch ([`CreateReport.php`, lines 26–39](https://github.com/braun-bauen/search-my-audio/blob/09de9bd6dbb4e7e4e7836e0030912fbf9c8309d3/app/Jobs/CreateReport.php#L26-L39)). With three worker attempts, this can generate multiple emails and repeated full report queries.
- There is no scheduled pruning. Laravel warns that `job_batches` can accumulate quickly and recommends daily `queue:prune-batches`; this repo's console schedule is empty ([`routes/console.php`](https://github.com/braun-bauen/search-my-audio/blob/09de9bd6dbb4e7e4e7836e0030912fbf9c8309d3/routes/console.php), [Laravel pruning batches](https://laravel.com/docs/12.x/queues#pruning-batches)). Failed jobs likewise have no retention policy.

**Architecture implication:** model every validation, storage, transcription, and report outcome as a terminal or retryable state; surface failed writes; centralize finalization; and add retention/pruning. These are prerequisites for trustworthy progress reporting and scale-to-zero operation.

## Secondary resource risks

### Polling multiplies web load while jobs run

The parent results component polls every two seconds until the search completes, while each visible uploaded file component also polls every two seconds ([`results.blade.php`, lines 129–170](https://github.com/braun-bauen/search-my-audio/blob/09de9bd6dbb4e7e4e7836e0030912fbf9c8309d3/resources/views/livewire/results.blade.php#L129-L170), [`file-results.blade.php`, lines 63–90](https://github.com/braun-bauen/search-my-audio/blob/09de9bd6dbb4e7e4e7836e0030912fbf9c8309d3/resources/views/livewire/file-results.blade.php#L63-L90)). With 20 visible processing files, the markup schedules up to **21 component polls per two seconds, or 10.5 requests/second per foreground browser**. Livewire explicitly calls polling resource-intensive and recommends longer intervals or visibility throttling; it already reduces background-tab polling by 95% ([Livewire polling](https://livewire.laravel.com/docs/3.x/wire-poll)). Consolidating progress into one slower poll—or push updates later—has a clearer payoff than micro-optimizing the page's individual SQL statements.

The parent also calls `count($search->files)`, which materializes the full relationship rather than issuing an aggregate count ([`results.blade.php`, lines 130–170](https://github.com/braun-bauen/search-my-audio/blob/09de9bd6dbb4e7e4e7836e0030912fbf9c8309d3/resources/views/livewire/results.blade.php#L130-L170)); Laravel provides `withCount` specifically to count without loading related models ([Laravel relationship counts](https://laravel.com/docs/11.x/eloquent-relationships#counting-related-models)).

### Transcription representation duplicates content and reads whole JSON objects

The stored JSON contains the complete transcript in `fullText` and also partitions the entire transcript into `segments`, so the raw textual content is stored roughly twice, plus JSON overhead ([`ProcessFile.php`, lines 189–247](https://github.com/braun-bauen/search-my-audio/blob/09de9bd6dbb4e7e4e7836e0030912fbf9c8309d3/app/Jobs/ProcessFile.php#L189-L247)). Viewing/copying a transcription loads and decodes the whole JSON object ([`AudioFile.php`, lines 65–87](https://github.com/braun-bauen/search-my-audio/blob/09de9bd6dbb4e7e4e7836e0030912fbf9c8309d3/app/Models/AudioFile.php#L65-L87)). This is bounded by transcript size rather than audio size, but it creates avoidable storage, allocation, and response payload overhead.

`CreateReport` loads every matching file model and constructs the complete CSV in one PHP string before writing it ([`CreateReport.php`, lines 41–71](https://github.com/braun-bauen/search-my-audio/blob/09de9bd6dbb4e7e4e7836e0030912fbf9c8309d3/app/Jobs/CreateReport.php#L41-L71)). The maintenance command similarly uses `AudioFile::all()` and reads every referenced transcription JSON sequentially ([`UpdateFileStatus.php`, lines 31–78](https://github.com/braun-bauen/search-my-audio/blob/09de9bd6dbb4e7e4e7836e0030912fbf9c8309d3/app/Console/Commands/UpdateFileStatus.php#L31-L78)). Both should stream/chunk if retained.

### Query indexes do not match the actual compound access paths

The results query always constrains `search_id`, may filter `query_count`, and orders by `query_count`, `parsed_date`, or `created_at` ([`results.blade.php`, lines 50–65](https://github.com/braun-bauen/search-my-audio/blob/09de9bd6dbb4e7e4e7836e0030912fbf9c8309d3/resources/views/livewire/results.blade.php#L50-L65)). The migration adds separate indexes on the three sort/filter columns, but no composite index beginning with `search_id` ([`add_file_indexes.php`](https://github.com/braun-bauen/search-my-audio/blob/09de9bd6dbb4e7e4e7836e0030912fbf9c8309d3/database/migrations/2025_04_30_181506_add_file_indexes.php)). History similarly filters by `user_id` and orders by one of three columns without explicit compound indexes ([`history.blade.php`, lines 50–55](https://github.com/braun-bauen/search-my-audio/blob/09de9bd6dbb4e7e4e7836e0030912fbf9c8309d3/resources/views/livewire/history.blade.php#L50-L55)).

These are plausible filesort/temporary-work risks as data grows, but production `EXPLAIN` and slow-query evidence are required before adding every possible composite index. The tables are paginated at 20 rows, and current low user volume makes them less credible crash causes than concurrency and lifecycle faults.

## Measurements required before choosing the target architecture

Capture these on the current VPS over at least one normal workload and one burst, keyed by job class and audio size/duration:

1. **Per-job:** wall time, OpenAI request time, attempts, timeout/failure reason, peak RSS, input bytes/duration, transcript bytes, result JSON bytes, and storage read/write latency.
2. **Per-search:** files submitted/accepted/terminal, queue wait, time-to-first/result completion, retries, duplicate reports/emails, and reconciliation mismatches between `query_total` and `SUM(files.query_count)`.
3. **Host/worker:** PHP-FPM and queue process count, aggregate/peak RSS, CPU saturation, load average, disk I/O, swap/OOM kills, and database lock/busy time at concurrency 1, 2, and 4.
4. **Database:** slow-query log plus `EXPLAIN` for results/history sorts, completion `doesntExist`, queue reservation, and batch updates using realistic row counts.
5. **Web:** Livewire requests per processing page, response bytes, SQL count/time, and object-storage reads. Verify the derived 10.5 req/s foreground upper bound with browser/network telemetry.
6. **Reliability drills:** forced HTTP timeout after OpenAI accepts a request, worker death after transcription but before DB save, failed storage put/move, final-file failure, two files finishing simultaneously, manual retry of a previously counted file, and deletion during processing.

Run a controlled concurrency sweep rather than guessing: one worker is the low-cost baseline; increase to two and four only if peak aggregate RSS remains comfortably below the platform limit, CPU is not saturated, error/duplicate rates remain zero, and reduced queue age justifies the cost. Keep web concurrency separately bounded.

## Recommended decision order

1. Define the durable file/search state machine, idempotency boundary, single finalizer, retry policy, and progress semantics.
2. Add measurements and failure-injection tests around that contract.
3. Split web, upload/finalization, and transcription workloads into explicitly configured queues; deploy with one transcription worker.
4. Consolidate/slow UI polling and eliminate full relationship/transcription loads from progress requests.
5. Measure query plans, then add only compound indexes supported by actual database evidence.
6. Compare hosting/transcription options using the measured per-job resource envelope and queue-delay tolerance.

## Bottom line

The low-cost design should assume slow, serial transcription with reliable queuing and accurate progress. It should not preserve the current “12 workers sharing a 2 GiB web container” topology. Correct lifecycle/idempotency first; measure at concurrency one; then scale workers horizontally only when observed queue delay requires it.
