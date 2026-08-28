# Representative transcription benchmark

Run on 2026-08-27 to inform the Search My Audio transcription-provider decision.

## Scope

Mistral was excluded by product-owner preference. The benchmark compares OpenAI
`gpt-transcribe` with the current `whisper-1` rollback.

The corpus contained four synthetic, human-checked English recordings totaling
51.95 seconds. macOS system voices produced the audio, so no user data or human
voice recording was disclosed. The recordings cover:

- ordinary prose with repeated keywords;
- faster operational language and the phrase `queue latency`;
- names, place names, and the product phrase `Search My Audio`;
- a negative control containing similar sounds but none of the absent target
  phrases.

The application uses case-insensitive exact-substring matching, so the primary
outcome was whether each transcript preserved nine known-present query phrases
and avoided three known-absent phrases. Each model was tested both without a
prompt and with the known search terms supplied through the transcription
endpoint's `prompt` field. Latencies are single-request wall-clock observations,
not a statistically stable performance study.

## Results

| Outcome | `gpt-transcribe` | `whisper-1` |
| --- | ---: | ---: |
| Unprompted present query phrases found | 6/9 | 7/9 |
| Query-hinted present query phrases found | 9/9 | 9/9 |
| Absent query phrases falsely found, unprompted | 0/3 | 0/3 |
| Absent query phrases falsely found, query-hinted | 0/3 | 0/3 |
| Mean unprompted latency across four clips | 3.28 s | 1.78 s |
| Mean query-hinted latency across four clips | 1.36 s | 1.56 s |
| Malformed WAV response | HTTP 400 in 0.61 s | HTTP 400 in 1.20 s |
| Published price per audio minute | $0.0045 | $0.006 |
| Estimated cost for this 51.95-second corpus per pass | $0.0039 | $0.0052 |

Unprompted, both models preserved ordinary phrases such as `renewable energy`,
`orchard`, and `queue latency`. Both missed `Siobhan` and `Braun Bauen`.
`whisper-1` additionally preserved `Nguyen`, giving it the one-query advantage.

Supplying the search vocabulary as a prompt fixed every tested positive miss for
both models. It did not cause either model to hallucinate the three prompted but
absent control phrases. The hinted transcripts were readable and materially
equivalent for the application's current transcript display.

Both models returned a prompt, non-retryable HTTP 400 for an empty file bearing
a `.wav` filename. The wording differed, but both correctly classified the
malformed input rather than timing out or returning an empty successful result.

## Conclusion

Use `gpt-transcribe` as the default OpenAI model and send the user's search query
as transcription context. Keep `whisper-1` as a configuration-only rollback.
On this small representative corpus, query hinting mattered more than model
choice, removed the observed exact-search recall difference, and introduced no
observed false positives. `gpt-transcribe` is also 25% cheaper at published
per-minute prices.

The implementation plan should require a regression corpus with real,
consented or privacy-safe domain audio before broad rollout. It should also
classify 4xx input errors as terminal and reserve retries for rate limits,
timeouts, and server failures.

## Limitations

- Four synthetic clips do not represent accents, background noise, overlapping
  speakers, music, radio compression, or long recordings.
- Each latency cell is based on one request per clip and is unsuitable for
  capacity sizing.
- Exact-search scoring measures the current product behavior, not general word
  error rate.
- Published pricing and model behavior can change and should be rechecked at
  implementation time.

## Official references

- [GPT Transcribe model](https://developers.openai.com/api/docs/models/gpt-transcribe)
- [Whisper model](https://developers.openai.com/api/docs/models/whisper-1)
- [Audio transcription API](https://platform.openai.com/docs/api-reference/audio/createTranscription)
