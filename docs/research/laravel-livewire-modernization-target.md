# Laravel and Livewire modernization target

Research date: 2026-08-27

## Decision

Target the latest supported releases within **Laravel 13.x**, **PHP 8.3+**, **Livewire 4.x**, and matching current **Flux / Flux Pro 2.x** releases. Migrate the application's Volt class-based single-file components to Livewire 4's native single-file components and then remove `livewire/volt`. Add **Blaze 1.x** only after Flux is at least 2.12.1, initially using Blaze's default compiler and treating memoization/folding as later, measured optimizations.

Evaluate `laravel/ai` as a contained replacement for the hand-written OpenAI HTTP adapter, not as a reason to redesign the job, persistence, search, or progress architecture. Its provider abstraction, storage integration, queue API, and test fakes are useful; the application must still own its durable transcription JSON contract and keyword segmentation.

## Current repository baseline

The locked dependency graph is substantially behind the constraints already declared by the application:

| Layer | Declared constraint | Locked version |
| --- | --- | --- |
| PHP | `^8.2` | runtime not pinned in the lockfile |
| Laravel | `^12.0` | 12.8.1 |
| Livewire | transitive | 3.6.3 |
| Flux / Flux Pro | `^2.0` / `^2.1` | 2.1.4 / 2.1.4 |
| Volt | `^1.6.7` | 1.7.1 |
| Laravel AI SDK | absent | absent |
| Blaze | absent | absent |

The UI is already on Tailwind 4, and its pages are predominantly Volt class-based single-file components under `resources/views/livewire`. Routes and tests call `Volt::route()` and `Volt::test()`, and `App\Providers\VoltServiceProvider` mounts that directory. The transcription job directly posts a storage stream to OpenAI's `/v1/audio/transcriptions` endpoint, extracts text, performs case-insensitive keyword segmentation, and persists an application-specific JSON document (`matchCount`, `segments`, and `fullText`).

## Supported target and boundaries

### Laravel and PHP

Laravel 13 is the appropriate planning target. Laravel's support table identifies Laravel 12 as receiving bug fixes only until 2026-08-13 and security fixes until 2027-02-24, while Laravel 13 receives bug fixes into Q3 2027 and security fixes into Q1 2028. Laravel 13 supports PHP 8.3–8.5 and requires PHP 8.3 at minimum. Laravel describes the release as intentionally low in breaking changes, but it is still a major-version upgrade and must be verified against all first- and third-party packages. ([Laravel 12 release notes and support policy](https://laravel.com/framework/docs/12.x/releases), [Laravel 13 release notes](https://laravel.com/framework/docs/13.x/releases))

As of the research date, the latest official framework release is 13.29.0. The implementation should retain caret constraints (`^13.0`) and resolve current patch releases at implementation time rather than pinning this snapshot. ([Laravel framework releases](https://github.com/laravel/framework/releases))

### Livewire 4 and native single-file components

Livewire 4 officially supports Laravel 10+ and PHP 8.1+, so Laravel 13 / PHP 8.3 is within its supported matrix. As of the research date the current release is 4.4.2. ([Livewire installation prerequisites](https://livewire.laravel.com/docs/4.x/installation), [Livewire releases](https://github.com/livewire/livewire/releases))

The upgrade is designed to preserve most Livewire 3 behavior, but the official guide identifies changes that are relevant here:

- replace the published v3 configuration with or carefully reconcile it against the v4 configuration;
- prefer `Route::livewire()` for full-page components;
- account for hash-bearing Livewire asset/update URLs in proxy or firewall rules;
- verify JavaScript event payloads, polling, pagination, `wire:model` modifiers, and any transition/loading behavior;
- retain explicit `wire:key` values in loops.

([Livewire 4 upgrade guide](https://livewire.laravel.com/docs/4.x/upgrading))

Livewire 4 natively supports the same class-based single-file syntax used by this repository's Volt pages. The official migration path is mechanical: change `Livewire\Volt\Component` to `Livewire\Component`, replace `Volt::route()` with `Route::livewire()`, replace `Volt::test()` with `Livewire::test()`, remove the Volt service provider, and uninstall `livewire/volt`. This should be the target state; keeping Volt would preserve an unnecessary compatibility layer. ([Livewire 4 upgrade guide: Upgrading Volt](https://livewire.laravel.com/docs/4.x/upgrading#upgrading-volt))

The current Volt package itself supports both Livewire 3 and 4, so a staged transition is technically possible if it lowers rollout risk. ([Volt `composer.json`](https://github.com/livewire/volt/blob/main/composer.json))

### Flux and Flux Pro

Flux 2 remains the supported UI line. Its current package constraint supports Livewire `^3.7.4|^4.0` and Laravel 10–13; therefore the repository's locked Flux 2.1.4 must be upgraded before moving Livewire to 4 because that old lock explicitly requires Livewire 3. The current Flux release is 2.17.1. Flux Pro should be upgraded in lockstep with Flux and its private Composer credentials must remain available in CI/deployment. ([Flux `composer.json`](https://github.com/livewire/flux/blob/main/composer.json), [Flux installation and deployment authentication](https://fluxui.dev/docs), [Flux releases](https://github.com/livewire/flux/releases))

Tailwind 4 is already present, satisfying Flux 2's frontend prerequisite. Published/customized Flux components should be reviewed against the intervening Flux changelog before upgrading. ([Flux 2 upgrade guide](https://fluxui.dev/docs/upgrading))

### Blaze

Blaze 1.x supports Laravel 10–13 and PHP 8.1+, but its current package explicitly conflicts with Flux versions older than 2.12.1. The supported sequence is therefore **upgrade Flux first, then install Blaze**. The current Blaze release is 1.0.18. ([Blaze `composer.json`](https://github.com/livewire/blaze/blob/main/composer.json), [Blaze releases](https://github.com/livewire/blaze/releases))

For Flux, installation is drop-in once compatible versions are installed. For application-owned Blade components, start with narrowly selected anonymous-component directories or individual `@blaze` directives. Do not initially enable memoization or folding: Blaze documents unsupported class-based components, view composers/lifecycle events, automatic `View::share()` injection, some cross-boundary `@aware` behavior, and rendering compiled components through `view()`. Folding freezes dynamic output at compile time and needs specific correctness tests. ([Blaze documentation and limitations](https://github.com/livewire/blaze))

Blaze optimizes Blade component rendering, not queue workers, database queries, audio upload, or transcription. It may reduce web-request CPU where pages render many Flux/anonymous components, but it is not evidence that the application's known job-resource problem will improve. Adoption should be benchmark-gated and independently reversible.

## Laravel AI SDK architectural impact

Laravel 13 documents a first-party AI SDK, installed as the separate `laravel/ai` Composer package. It exposes `Transcription::fromPath()`, `fromStorage()`, and `fromUpload()`, synchronous generation, queued generation with completion callbacks, provider/model configuration, and transcription fakes/assertions. Its provider support includes OpenAI, so it can replace the repository's hand-built authenticated multipart request while keeping the current provider. ([Laravel AI SDK documentation](https://laravel.com/framework/docs/13.x/ai-sdk), [Laravel AI SDK source and releases](https://github.com/laravel/ai))

The SDK is not restricted to Laravel 13: the current 0.x package accepts Illuminate 12 or 13, but requires PHP 8.3 and relatively recent framework components (currently `illuminate/json-schema` `^12.62|^13.15`). Thus it could be evaluated on a fully updated Laravel 12 application, but Laravel 13 is the cleaner common target. The package remains pre-1.0 (0.x), so isolate it behind an application-owned transcription interface and pin it with an intentional constraint rather than exposing SDK response types throughout the domain. ([Laravel AI SDK `composer.json`](https://github.com/laravel/ai/blob/0.x/composer.json), [Laravel AI SDK releases](https://github.com/laravel/ai/releases))

The SDK does **not** eliminate the important application responsibilities visible in `ProcessFile`:

- preserving the existing stored JSON shape so historical and newly generated user data remain accessible;
- keyword matching and segmentation;
- batch cancellation, retry/idempotency, file/search status transitions, and aggregate match counts;
- durable, user-visible progress and completion notification;
- provider privacy, retention, model behavior, cost, and response-shape decisions.

Accordingly, SDK adoption is an adapter decision. It is worthwhile if a focused spike proves that storage streams/files supported by the deployment disk, output fidelity, error classification, timeouts/retries, and testing hooks meet this job's needs. The queue API should not automatically replace the existing outer `ProcessFile` orchestration: doing so would introduce nested asynchronous ownership and make reliable progress harder to reason about.

## Recommended implementation order

1. **Establish an upgrade safety net.** Add characterization coverage for routes, auth pages, upload, polling/progress, completion, retry, stored transcription rendering, keyword segmentation, and browser-event behavior. Record a production-like dependency/platform baseline.
2. **Raise the platform and refresh Laravel 12 first.** Move the runtime constraint to PHP 8.3+, update Laravel 12 and all supporting packages to their latest compatible releases, and resolve deprecations. This creates the minimum platform for `laravel/ai` and narrows the later major-version diff.
3. **Upgrade the UI dependency family.** Upgrade Flux and Flux Pro together to current 2.x (at least 2.12.1), while remaining on Livewire 3 if the solver permits; verify customized/published components and the Flux Pro credential path.
4. **Upgrade Livewire and retire Volt.** Move to Livewire 4, reconcile configuration, migrate component imports/routes/tests/provider, and remove Volt once the native single-file components pass characterization and browser tests.
5. **Upgrade Laravel to 13.** Update framework and supporting packages under PHP 8.3+, run the framework upgrade guide and full test suite, and verify queue serialization, filesystem, mail, Cashier, Sentry, and production build behavior.
6. **Evaluate and optionally adopt Laravel AI behind a local interface.** Preserve the stored JSON schema and existing job ownership. Compare the SDK adapter with the current OpenAI adapter for output, failures, privacy documentation, tests, and cost before switching production traffic.
7. **Trial Blaze last.** Install Blaze 1.x, benchmark representative pages, enable the default compiler only on verified compatible components, and expand only when measured CPU/latency savings justify it. Treat memoization/folding as separate optimizations.

Steps 4 and 5 may be combined in one implementation branch if Composer constraints require it, but keeping the verification checkpoints distinct will make failures attributable and rollback safer.

## Verification gates

- A clean Composer solve on the intended PHP version and production extensions, with no abandoned packages or security advisories.
- Full automated test pass plus browser checks for every preserved page and interactive path.
- Existing transcription JSON fixtures render identically after the upgrade; new results conform to the same versioned contract.
- Queue jobs survive retry, timeout, worker restart, duplicate delivery, and batch cancellation without double-counting or false completion.
- Flux Pro authenticates in local CI and the intended hosting environment without committing credentials.
- Blaze produces equivalent HTML for its enabled component scope and shows a measured benefit on representative pages.

## Open decisions for later tickets

- Whether the Laravel AI adapter preserves the needed transcription fidelity and provider/privacy/cost envelope.
- Whether job reliability and progress should retain the current orchestration or adopt a revised state model.
- Whether measured page-rendering cost makes Blaze worth enabling beyond Flux's automatic integration.
- Exact production platform and deployment sequence, including the brief maintenance window and rollback mechanics.
