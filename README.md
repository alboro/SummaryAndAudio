# SummaryAndAudio — alboro fork

> This is a fork of [win-100/SummaryAndAudio](https://github.com/win-100/SummaryAndAudio) — a FreshRSS extension that summarizes and reads articles aloud using OpenAI-compatible APIs.  
> Original README and documentation: see the upstream repository.

---

## This fork

**Vibe-coded with AI assistance. Use at your own risk. No guarantees of correctness, security, or sanity.**  
*Плод безответственного вайб-кодинга.*

### Bugs fixed

| # | Problem | Fix |
|---|---------|-----|
| 1 | `fetchTtsParamsAction()` leaked the API key to the browser (returned `oai_key` in JSON) | Endpoint now returns **403 Forbidden** |
| 2 | SSE streaming didn't stream — text appeared all at once | Apache `mod_deflate` was buffering chunks for gzip. Fixed with `apache_setenv('no-gzip', '1')` + clearing all PHP output buffers before the first SSE chunk |
| 3 | TTS audio loaded via `<audio src="GET URL">` — broke with long content (URL length limits) and CSRF | Replaced with `fetch POST` → blob URL → `new Audio(blobUrl)` |
| 4 | `media-src *` in CSP doesn't include `blob:` — audio playback blocked | Added `blob:` to `media-src` and `default-src` CSP policies |
| 5 | Article-level "Read" button did nothing when article had no `<p>` tags | Falls back to reading the entire `.oai-summary-article` text as one TTS request |
| 6 | `reasoning.effort: "minimal"` rejected by backend | Normalized to `"low"` |
| 7 | TTS timeout for long texts (47 s for 3760 chars) | Chunked into ≤600-char pieces (~7 s each); `set_time_limit(120)` |
| 8 | URLs double-encoded in data attributes (`&amp;amp;`) | Separated URL attrs (raw) from i18n attrs (htmlspecialchars) |

### Features added

| Feature | Description |
|---------|-------------|
| **Configurable buttons** | Each button has its own `label`, `url`, `key`, `model`, and `prompt`. Number of buttons is set in extension config (add/remove via UI). Stored as JSON in `oai_buttons` user config key. |
| **Auto-fetch full article** | If RSS entry content is < 200 chars, the extension fetches the article URL and passes the full page text to the AI. |
| **TTS for AI result** | After summary/translation completes, a play button appears inside the result box. Reads the AI-generated text aloud. |
| **`%rss_content%` in prompts** | Use `%rss_content%` anywhere in a prompt template — it's replaced with the article's Markdown text before sending to the AI. Enables fully custom prompt construction without a fixed "system + user" split. |
| **Removed Ollama/OpenAI provider split** | All requests use the OpenAI Responses API (`/v1/responses`). Any OpenAI-compatible backend works. |
| **Backward-compatible** | Falls back to the old flat config keys (`oai_url`, `oai_key`, `oai_model`, `oai_prompt`, `oai_prompt_2`) if `oai_buttons` is not set. |
| **TTS text normalization** | Configurable pre-processing of text before sending to TTS: `simple` (fast rule-based cleanup of Markdown/HTML/URLs) or `llm` (sends text through an LLM to rewrite for natural speech). Default: `llm`. |
| **Per-context TTS voice** | Different voices for article TTS (`oai_voice`) and result TTS (`oai_voice_result`). The voice parameter is sent per-request. |
| **Gapless chunk playback** | Next TTS chunk is prefetched while the current one is playing — eliminates pauses between chunks. |
| **Waiting state indicators** | Buttons show a spinner animation while waiting for API responses (both summary and TTS). |
| **i18n: ru, lv, en, fr** | Full localization including the LLM normalizer system prompt. |

### Architecture

The extension uses a service-layer architecture with dependency injection for testability:

| Service | Role |
|---------|------|
| `ArticleContentProvider` | Extracts article text, auto-fetches when RSS content < 200 chars |
| `ButtonConfigParser` | Parses JSON or legacy flat-key configs into `ButtonConfig[]` |
| `OpenAiClientInterface` / `HttpOpenAiClient` | Streams requests to OpenAI-compatible endpoints |
| `TtsService` | Full TTS pipeline: text normalization → audio streaming |
| `TextNormalizerInterface` | Contract for TTS pre-processing |
| `SimpleTextNormalizer` | Rule-based cleanup (Markdown, HTML, URLs, typography) |
| `LlmTextNormalizer` | LLM-backed rewriting via `/chat/completions` |
| `HtmlToMarkdownConverter` | DOM-based HTML→Markdown conversion |

All services are covered by **PHPUnit tests** (60+ tests, 150+ assertions).

### JS modules

Frontend JavaScript is split into logical modules:

- `summary.js` — summarization button logic + SSE streaming
- `tts.js` — TTS playback, chunking, prefetch, per-context voice
- `script.js` — entry point, event delegation

### Prompt template example (`%rss_content%`)

```
You are Friedrich Nietzsche, in your prime. Read the following news article and deliver
a verdict — not an analysis, a verdict — on what it reveals about the modern slave morality,
the herd instinct, or the rare signs of will to power, if any.

%rss_content%
```

When `%rss_content%` is present in the prompt, the article text is embedded directly in the user message (no separate system role). When absent, the default behavior applies: prompt → system role, article → user role.

---

*Fork maintained for personal use. PRs welcome but not expected.*
