# Transcription integrations, privacy, and cost

Research date: 2026-08-27

## Decision summary

Adopt Laravel AI as the provider boundary **after the PHP 8.3 upgrade**, but do not change providers blindly. Benchmark `gpt-transcribe` and Mistral `voxtral-mini-latest` against a small, representative, human-checked audio set. OpenAI is the conservative default because it preserves the existing endpoint and unusually has no standard retention for transcription inputs or outputs; Mistral is the leading cost challenger at $0.003/minute and adds timestamps, diarization, and vocabulary biasing. Keep `whisper-1` available for rollback until transcript and keyword-match parity is proven.

## Current application contract

- `app/Jobs/UploadFile.php` accepts WAV, MP3/MPEG, and MP4 up to 25 MiB. This exactly matches OpenAI's 25 MB transcription limit, although OpenAI also accepts M4A and WebM ([OpenAI file transcription guide](https://developers.openai.com/api/docs/guides/speech-to-text#file-uploads)).
- `app/Jobs/ProcessFile.php` sends a storage stream to `/v1/audio/transcriptions` with `model=whisper-1`, English, and plain JSON. Only the returned `text` is consumed. The app then performs its own case-insensitive literal keyword search and stores its own segment schema. No provider timestamps or speaker labels are currently required.
- The practical migration contract is therefore plain text equivalence, not provider-specific segment equivalence. Existing stored transcription JSON should remain untouched and readable.

## Integration decision: use Laravel AI as a boundary, not as a reason to add AI features

The first-party Laravel AI SDK supports speech-to-text through OpenAI, OpenAI-compatible endpoints, ElevenLabs, Groq, Mistral, and Gemini. It accepts Laravel storage paths, supports queueing, exposes provider/model selection, returns text and segments, and includes transcription fakes for tests ([Laravel AI SDK documentation](https://laravel.com/framework/docs/13.x/ai-sdk#transcriptions), [provider support](https://laravel.com/framework/docs/13.x/ai-sdk#provider-support)). Those are concrete improvements over the current handwritten HTTP call: credentials and model selection move to configuration, provider changes stop leaking into the job, and external calls become straightforward to fake.

There are two cautions:

1. The package remains pre-1.0 (currently the `0.x` line), so upgrades can change its API. Pin a reviewed minor version rather than allowing unconstrained pre-1.0 updates ([official package releases/source](https://github.com/laravel/ai)).
2. Its current package requires PHP 8.3 and Laravel 12 or 13, while this repository declares PHP `^8.2` and Laravel `^12.0` ([official `composer.json`](https://github.com/laravel/ai/blob/0.x/composer.json)). Adoption therefore belongs after the runtime/dependency upgrade, not inside an isolated transcription change.

Do not use Laravel AI's extra internal queued-transcription layer inside the existing `ProcessFile` job. Keep one application-owned job as the durable unit of progress/retry and call `Transcription::fromStorage(...)->generate(...)` within it; nested async callbacks would make the existing batch completion, failure state, and notification flow harder to reason about.

## Viable provider/model shortlist

Prices are public list prices on the research date and exclude taxes, storage, network transfer, and optional enterprise controls.

| Choice | Public price | Fit and features | Privacy / retention | Migration risk |
|---|---:|---|---|---|
| OpenAI `whisper-1` (rollback baseline) | $0.006/min | Existing behavior; only `whisper-1` supports OpenAI word timestamps via `verbose_json` ([model card](https://developers.openai.com/api/docs/models/whisper-1), [timestamp docs](https://developers.openai.com/api/docs/guides/speech-to-text#timestamp-granularities)). | API data is not trained on unless opted in; `/v1/audio/transcriptions` has no abuse-monitoring or application-state retention and is ZDR eligible ([OpenAI data controls](https://developers.openai.com/api/docs/guides/your-data#data-retention-controls)). | Lowest: already in production. Keeping it gains no accuracy/cost improvement. |
| OpenAI `gpt-transcribe` | $0.0045/min | OpenAI's current high-accuracy file model; supports streamed file results, keyword hints, language hints, and contextual prompting ([model card](https://developers.openai.com/api/docs/models/gpt-transcribe), [transcription guide](https://developers.openai.com/api/docs/guides/speech-to-text)). Same 25 MB endpoint contract. | Same `/v1/audio/transcriptions` policy: no standard input/output retention and no training unless opted in ([data controls](https://developers.openai.com/api/docs/guides/your-data#data-retention-controls)). | Low. Same provider, endpoint, key, multipart upload, and plain-text result. Validate Laravel AI model/options mapping. |
| OpenAI `gpt-4o-mini-transcribe` | Token-priced ($1.25/M audio input, $5/M output) | OpenAI says it improves word error rate and language recognition over Whisper; accepts prompts and file streaming, but not Whisper's word-timestamp parameter ([model card](https://developers.openai.com/api/docs/models/gpt-4o-mini-transcribe), [feature notes](https://developers.openai.com/api/docs/guides/speech-to-text)). | Same favorable transcription endpoint policy. | Low, but its token-based bill is less predictable than per-minute alternatives and it offers no needed capability over `gpt-transcribe` for this app. |
| Mistral `voxtral-mini-latest` (Voxtral Mini Transcribe 2) | $0.003/min | Up to 3-hour recordings, 13 languages, word timestamps, diarization, and up to 100 context-bias terms ([Mistral audio overview](https://docs.mistral.ai/studio/audio/overview), [pricing](https://mistral.ai/pricing/api/)). Directly supported by Laravel AI. | Pay-as-you-go Studio inputs/outputs are not used for training; zero data retention can be requested. EU regional inference costs 10% more ([training policy](https://help.mistral.ai/en/articles/347617-do-you-use-my-user-data-to-train-your-artificial-intelligence-models), [workspace privacy controls](https://help.mistral.ai/en/articles/316347-what-settings-can-be-configured-in-the-admin-console), [pricing](https://mistral.ai/pricing/api/)). Public docs do not state the ordinary retention duration as clearly as OpenAI, so confirm it contractually before production. | Medium. New account/key/provider and new response mapping, but Laravel AI has a native driver. The 25 MiB inputs are comfortably within duration limits. |
| Gemini `gemini-3.5-transcribe` | About $0.005/min blended | Automatic language detection, word timestamps, diarization, and vocabulary hints; file requests allow 1 hour, or 30 minutes with timestamps/diarization ([Gemini transcription docs](https://ai.google.dev/gemini-api/docs/transcribe), [pricing](https://ai.google.dev/gemini-api/docs/pricing#gemini-3.5-transcribe)). Laravel AI lists Gemini STT support. | Paid Gemini API data is marked as not used to improve products. Stronger zero-retention controls are documented for Vertex AI, but require Google Cloud configuration and may include abuse-monitoring exceptions ([Gemini pricing](https://ai.google.dev/gemini-api/docs/pricing#gemini-3.5-transcribe), [Vertex AI ZDR](https://cloud.google.com/vertex-ai/generative-ai/docs/vertex-ai-zero-data-retention)). | Medium-high. The official workflow first uploads files and uses the Interactions API, so verify that Laravel AI's Gemini driver supports this newly released model and deletes uploaded files appropriately. No cost/privacy advantage over the two leaders. |
| ElevenLabs Scribe v2 | $0.22/hour ($0.00367/min) plus a paid plan | Up to 3 GB/10 hours, word timestamps, diarization up to 32 speakers, keyterm prompting, webhooks, and broad formats ([STT docs](https://elevenlabs.io/docs/overview/capabilities/speech-to-text/), [pricing](https://elevenlabs.io/speech-to-text)). Laravel AI has a native driver. | Default history retention is enabled. STT zero-retention and regional isolation exist only for selected enterprise customers; deleted retained data may remain in backups up to 30 days ([ZRM docs](https://elevenlabs.io/docs/eleven-api/resources/zero-retention-mode), [residency docs](https://elevenlabs.io/docs/overview/administration/data-residency)). | Medium. Good technical fit, but its plan/account overhead and weaker default retention posture are poor fits for a tiny cost-sensitive app. |

Self-hosted Whisper/Voxtral is not shortlisted. Scale-to-zero GPU cold starts, model download/storage, monitoring, and idle capacity would shift complexity and cost into infrastructure, contrary to this project's low-volume goal. Revisit only when measured transcription volume makes managed per-minute pricing material.

## Recommended decision sequence

1. Upgrade the runtime to PHP 8.3+ and install a pinned Laravel AI release as part of the framework modernization work.
2. Introduce an application-owned `Transcriber` boundary backed by Laravel AI. Preserve the current plain-text-to-existing-JSON pipeline and keep provider/model entirely in configuration.
3. Build a small private evaluation set representing actual recordings: clean/noisy, short/near-25-MiB, accents, and known occurrences/non-occurrences of real search terms. Store only expected transcripts and keyword counts that are safe to keep in the repository; keep sensitive audio outside Git.
4. Compare `whisper-1`, `gpt-transcribe`, and `voxtral-mini-latest` on transcription latency, failure rate, human correction burden, and—most importantly for this product—false-positive/false-negative keyword matches. Provider-published accuracy claims are not comparable enough to decide this product's outcome.
5. Default to `gpt-transcribe` if quality is comparable because it improves price, retains the existing provider/account, and has the clearest favorable retention policy. Choose Mistral only if the corpus demonstrates equivalent or better search outcomes and its ordinary retention terms are confirmed. Keep `whisper-1` as a config-only rollback through rollout.
6. Defer timestamps and diarization. They do not serve the current UI contract and would enlarge stored results. Add them only behind a separately decided user feature.

## Cost perspective

At 1,000 audio minutes/month, list-price transcription is approximately $6 for Whisper, $4.50 for `gpt-transcribe`, $3 for Mistral, $5 for Gemini, or $3.67 for ElevenLabs before subscription effects. The maximum difference among the practical leaders is only $1.50/month at that volume. For a small app, reliability, privacy clarity, and migration effort dominate provider price; infrastructure and inefficient job/query work are more likely to produce meaningful savings.

## Acceptance checks for the later implementation

- Existing transcript JSON and pages remain readable without data migration.
- Provider/model switching requires configuration only and has an automated fake-backed contract test.
- Retries are idempotent and do not double-increment keyword counts or send duplicate completion mail.
- Logs never include provider response bodies or user audio/transcript content; secrets come from configuration, not direct `env()` calls in application code.
- A failed/cold-started provider call reports honest queued/processing/failed progress and can fall back manually to `whisper-1`.
- Production rollout uses a small canary batch and records per-provider latency, error, and cost metadata without retaining audio at the provider beyond its documented policy.
