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
| 6 | `reasoning.effort: "minimal"` rejected by ChatGPT backend | Normalized to `"low"` in the codex proxy |

### Features added

| Feature | Description |
|---------|-------------|
| **Configurable buttons** | Each button has its own `label`, `url`, `key`, `model`, and `prompt`. Number of buttons is set in extension config (add/remove via UI). Stored as JSON in `oai_buttons` user config key. |
| **Auto-fetch full article** | If RSS entry content is < 200 chars, the extension fetches the article URL and passes the full page text to the AI. |
| **TTS for AI result** | After summary/translation completes, a play button appears inside the result box. Reads the AI-generated text aloud. |
| **`%rss_content%` in prompts** | Use `%rss_content%` anywhere in a prompt template — it's replaced with the article's Markdown text before sending to the AI. Enables fully custom prompt construction without a fixed "system + user" split. |
| **Removed Ollama/OpenAI provider split** | All requests use the OpenAI Responses API (`/v1/responses`). Any OpenAI-compatible backend works. Provider config is just the button's URL + key. |
| **Backward-compatible** | Falls back to the old flat config keys (`oai_url`, `oai_key`, `oai_model`, `oai_prompt`, `oai_prompt_2`) if `oai_buttons` is not set. |

### Prompt template example (`%rss_content%`)

```
You are Karl Marx. Give a brief materialist commentary on this article.

Article:
%rss_content%

Commentary (2-3 paragraphs, in Russian):
```

When `%rss_content%` is present in the prompt, the article text is embedded directly in the user message (no separate system role). When absent, the default behavior applies: prompt → system role, article → user role.

---

*Fork maintained for personal use. PRs welcome but not expected.*
