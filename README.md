# RssAiButtons — alboro fork
> Substantially reworked fork of [win-100/SummaryAndAudio](https://github.com/win-100/SummaryAndAudio).  
> A FreshRSS extension that adds configurable AI action buttons per article: summary, translation, analysis — anything you prompt — plus text-to-speech via OpenAI-compatible APIs.
---
## This fork
**Vibe-coded with AI assistance. Use at your own risk. No guarantees of correctness, security, or sanity.**  
*Плод безответственного вайб-кодинга.*
> **Note:** The extension has been substantially reworked compared to the upstream.  
> It was previously known as `SummaryAndAudio` — renamed to `RssAiButtons` to better reflect its purpose.
### Bugs fixed
| # | Problem | Fix |
|---|---------|-----|
| 1 | `fetchTtsParamsAction()` leaked the API key to the browser (returned `oai_key` in JSON) | Endpoint now returns **403 Forbidden** |
| 2 | SSE streaming didn't stream ? RssAiButtons — alboro fork
> Substantially reworked fork of [win-100/Summzi> Substantially reworked fork'n> A FreshRSS extension that adds configurable AI action buttons per article: summary, translation, analy `---
## This fork
**Vibe-coded with AI assistance. Use at your own risk. No guarantees of correctness, security, or sanity.**  
*Плод безответственного вайб-? ##cl**Vibe-code ?Плод безответственного вайб-кодинга.*
> **Note:** The extension has been sul > **Note:** The extension has been substantially reworked compared to t> It was previously known as `SummaryAndAudio` — renamed to `RssAiButtons` to bettng### Bugs fixed
| # | Problem | Fix |
|---|---------|-----|
| 1 | `fetchTtsParamsAction()` leaked the API kor| # | Problem| |---|---------|-----ch| 1 | `fetchTtsParam);| 2 | SSE streaming didn't stream ? RssAiButtons — alboro fork
> Substantially reworked fork of [win-100/Summzi> Substantially rewoec> Substantially reworked fork of [win-100/Summzi> Substantially r--## This fork
**Vibe-coded with AI assistance. Use at your own risk. No guarantees of correctness, security, or sanity.**  
*Плод безответственного вайб-? ##cl**V a**Vibe-codeoa*Плод безответственного вайб-? ##cl**Vibe-code ?Плод безответствеsi> **Note:** The extension has been sul > **Note:** The extension has been substantially reworked compared to t> It was previously known as `Sumto| # | Problem | Fix |
|---|---------|-----|
| 1 | `fetchTtsParamsAction()` leaked the API kor| # | Problem| |---|---------|-----ch| 1 | `fetchTtsParam);| 2 | SSE streaming didn't stream ? RssAiButtons — alor|---|---------|----- E| 1 | `fetchTtsParampr> Substantially reworked fork of [win-100/Summzi> Substanti| **Removed Ollama/OpenAI provider split** | All requests use the OpenAI Responses API (`/v1/responses`). Any OpenAI**Vibe-coded with AI assistance. Use at your own risk. No guarantees of correctness, security, or sanity.**  
*Плод безответственнai*Плод безответственного вайб-? ##cl**V a**Vibe-codeoa*Плод безответс? |---|---------|-----|
| 1 | `fetchTtsParamsAction()` leaked the API kor| # | Problem| |---|---------|-----ch| 1 | `fetchTtsParam);| 2 | SSE streaming didn't stream ? RssAiButtons — alor|---|---------|----- E| 1 | `fetchTtsParampr> Substantially reworked fork of [win-100/Summzi> Substanti| **Removed Ollama/OpenAI provider split** | All requests use  || 1 | `fetchTtsParamre*Плод безответственнai*Плод безответственного вайб-? ##cl**V a**Vibe-codeoa*Плод безответс? |---|---------|-----|
| 1 | `fetchTtsParamsAction()` leaked the API kor| # | Problem| |---|---------|-----ch| 1 | `fetchTtsParam);| 2 | SSE streaming didn't stream ? RssAiButtons — alor|---|---------|----- E| 1 | `fetchTtsParampr> Substantially reworked fork of [win-100/Summzi> Substanti| **Removed Ollama/OpenAI provider split** | All req, | 1 | `fetchTtsParamsAction()` leaked the API kor| # | Problem| |---|---------|-----ch| 1 | `fetchTtsParam);| 2 | SSE streaming didn't stream ? RssAiButtons — alor|--iC| 1 | `fetchTtsParamsAction()` leaked the API kor| # | Problem| |---|---------|-----ch| 1 | `fetchTtsParam);| 2 | SSE streaming didn't stream ? RssAiButtons — alor|---|---------|----- E| 1 | `fetchTtsParampr> Substantially reworked fork of [win-100/Summzi> Substanti| **Removed Ollama/OpenAI provider split** | All req, | 1 | `fetchTtsParamsAction()` leaked the API kor| # | Problem| |---|---------|-----ch| 1 | `fetchTtsParam);| 2 | SSE streaming didn't stream ? RssAiButtons — alor|--iC| 1 | `fetchTtsParamsAction()`aScript is split into logical modules:
- `summary.js` — summarization button logic + SSE streaming
- `tts.js` — TTS playback, chunking, prefetch, per-context voice
- `script.js` — entry point, event delegation
### Prompt template example (`%rss_content%`)
\`\`\`
You are Friedrich Nietzsche, in your prime. Read the following news article and deliver
a verdict — not an analysis, a verdict — on what it reveals about the modern slave morality,
the herd instinct, or the rare signs of will to power, if any.
%rss_content%
\`\`\`
When `%rss_content%` is present in the prompt, the article text is embedded directly in the user message (no separate system role). When absent, the default behavior applies: prompt → system role, article → user role.
---
*Fork maintained for personal use. PRs welcome but not expected.*
