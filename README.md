# RssAiButtons

A FreshRSS extension that adds configurable AI action buttons per article: summary, translation, analysis — anything you prompt — plus text-to-speech via OpenAI-compatible APIs.

> Note: this project is a reworked fork of [win-100/SummaryAndAudio](https://github.com/win-100/SummaryAndAudio) and has been renamed to `RssAiButtons`.

## Important notes

- Vibe-coded with AI assistance.
- This fork removes the Ollama/OpenAI provider split: by default requests use the OpenAI Responses API (`/v1/responses`).

## Bugs fixed in this fork

| # | Problem | Fix |
|---:|---------|-----|
| 1 | `fetchTtsParamsAction()` leaked the API key to the browser (returned `oai_key` in JSON) | Endpoint now returns 403 Forbidden and does not expose secrets |
| 2 | SSE streaming didn't stream — text appeared all at once | `mod_deflate` was buffering chunks; fixed with `apache_setenv('no-gzip', '1')` and clearing all PHP output buffers before the first SSE chunk |
| 3 | TTS audio loaded via `<audio src="GET URL">` — broke with long content (URL length limits) and CSRF | Replaced with `fetch POST` → blob URL → `new Audio(blobUrl)` |
| 4 | `media-src *` in CSP didn't include `blob:` — audio playback blocked | Added `blob:` to `media-src` and `default-src` CSP policies |
| 5 | Article-level "Read" button did nothing when article had no `<p>` tags | Falls back to reading the entire `.oai-summary-article` text as a single TTS request |
| 6 | `reasoning.effort: "minimal"` rejected by backend | Normalized to `"low"` |
| 7 | TTS timeout for long texts (47 s for 3760 chars) | Chunked into ≤600‑char pieces (~7 s each); use `set_time_limit(120)` for long requests |
| 8 | URLs double-encoded in data attributes (`&amp;amp;`) | Separated URL attributes (raw) from i18n attributes (escaped with `htmlspecialchars`) |

## Project structure (high level)

The JavaScript is split into logical modules:
- `static/summary.js` — summarization button logic + SSE streaming
- `static/tts.js` — TTS playback, chunking, prefetch, per-context voice
- `static/script.js` — entry point, event delegation

PHP services live under `Services/` and controllers under `Controllers/`.

## Prompt template example (`%rss_content%`)

```
You are Friedrich Nietzsche, in your prime. Read the following news article and deliver
a verdict — not an analysis, a verdict — on what it reveals about the modern slave morality,
the herd instinct, or the rare signs of will to power, if any.

%rss_content%
```

When `%rss_content%` is present in the prompt, the article text is embedded directly in the user message (no separate system role). When absent, the default behavior applies: prompt → system role, article → user role.

---

Fork maintained for personal use. PRs welcome but not expected.
