<?php

// Load service layer (FreshRSS does not have an autoloader for extensions)
foreach ([
    __DIR__ . '/../Services/ButtonField.php',
    __DIR__ . '/../Services/AiButton.php',
    __DIR__ . '/../Services/ButtonConfig.php',       // backward-compat alias
    __DIR__ . '/../Services/AiButtonCollection.php',
    __DIR__ . '/../Services/ButtonConfigParser.php',
    __DIR__ . '/../Services/ArticleFetcherInterface.php',
    __DIR__ . '/../Services/HttpArticleFetcher.php',
    __DIR__ . '/../Services/HtmlToMarkdownConverter.php',
    __DIR__ . '/../Services/ArticleContentProvider.php',
    __DIR__ . '/../Services/OpenAiRequestDto.php',
    __DIR__ . '/../Services/OpenAiClientInterface.php',
    __DIR__ . '/../Services/HttpOpenAiClient.php',
    __DIR__ . '/../Services/TextNormalizerInterface.php',
    __DIR__ . '/../Services/SimpleTextNormalizer.php',
    __DIR__ . '/../Services/LlmTextNormalizer.php',
    __DIR__ . '/../Services/TtsService.php',
] as $_svc_file) {
    require_once $_svc_file;
}

class FreshExtension_RssAiButtons_Controller extends Minz_ActionController
{
    /**
     * Default English prompt used when no prompt is configured via Ansible/UI.
     * Override via oai_tts_normalize_prompt config key.
     * Supports %article_title% placeholder (replaced at runtime).
     */
    private const TTS_NORMALIZE_PROMPT_DEFAULT =
        'You are a reader. Transform the incoming text for audio playback: '
        . 'remove or replace with words all formatting and special characters — '
        . 'Markdown markup, HTML tags, URLs, mathematical and typographic symbols, '
        . 'emoji and any characters that would be pronounced literally during speech synthesis. '
        . 'Do not add explanations or comments — return only the transformed text.';

    // ── Service factories ───────────────────────────────────────────────────

    protected function makeContentProvider(): ArticleContentProvider
    {
        return new ArticleContentProvider(
            new HttpArticleFetcher(),
            new HtmlToMarkdownConverter()
        );
    }

    protected function makeOpenAiClient(): OpenAiClientInterface
    {
        return new HttpOpenAiClient();
    }

    protected function makeButtonConfigParser(): ButtonConfigParser
    {
        return new ButtonConfigParser();
    }

    protected function makeTtsNormalizer(string $articleTitle = ''): TextNormalizerInterface
    {
        $choice = trim((string)(FreshRSS_Context::$user_conf->oai_tts_normalizer ?? 'simple'));

        if ($choice !== 'llm') {
            return new SimpleTextNormalizer();
        }

        // LLM normalizer: reuse button[0]'s connection (same LLM as summarizer)
        $buttons = $this->loadButtons();
        $btn0    = $buttons->get(0);

        if ($btn0 === null || $this->isEmpty(trim($btn0->url)) || $this->isEmpty(trim($btn0->key))) {
            return new SimpleTextNormalizer(); // graceful fallback
        }

        $prompt = trim((string)(FreshRSS_Context::$user_conf->oai_tts_normalize_prompt ?? ''));
        if ($this->isEmpty($prompt)) {
            $prompt = self::TTS_NORMALIZE_PROMPT_DEFAULT;
        }

        // Substitute %article_title% placeholder if present
        if ($articleTitle !== '' && strpos($prompt, '%article_title%') !== false) {
            $prompt = str_replace('%article_title%', $articleTitle, $prompt);
        }

        return new LlmTextNormalizer(
            $this->makeOpenAiClient(),
            trim($btn0->url),
            trim($btn0->key),
            trim($btn0->model),
            $prompt
        );
    }

    protected function makeTtsService(string $articleTitle = ''): TtsService
    {
        return new TtsService(
            $this->makeOpenAiClient(),
            $this->makeTtsNormalizer($articleTitle)
        );
    }

    // ── Config helpers ──────────────────────────────────────────────────────

    /**
     * @return ButtonConfig[]
     */
    private function loadButtons(): array
    {
        $parser = $this->makeButtonConfigParser();
        $json   = FreshRSS_Context::$user_conf->oai_buttons;

        if ($json) {
            $buttons = $parser->parseJson((string)$json);
            if (count($buttons) > 0) {
                return $buttons;
            }
        }

        return $parser->parseLegacy(
            (string)(FreshRSS_Context::$user_conf->oai_url      ?? ''),
            (string)(FreshRSS_Context::$user_conf->oai_key      ?? ''),
            (string)(FreshRSS_Context::$user_conf->oai_model    ?? ''),
            (string)(FreshRSS_Context::$user_conf->oai_prompt   ?? ''),
            (string)(FreshRSS_Context::$user_conf->oai_prompt_2 ?? '')
        );
    }

    private function prepareSseHeaders(string $contentType): void
    {
        header('Content-Type: ' . $contentType);
        header('Cache-Control: no-cache, no-transform');
        header('X-Accel-Buffering: no');
        if (function_exists('apache_setenv')) {
            apache_setenv('no-gzip', '1');
            apache_setenv('dont-vary', '1');
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        ob_implicit_flush(true);
    }

    private function isEmpty($item): bool
    {
        return $item === null || (is_string($item) && trim($item) === '');
    }

    private function debugLog(string $msg): void
    {
        $line = date('Y-m-d H:i:s') . ' ' . $msg . "\n";
        file_put_contents('/tmp/saa_debug.log', $line, FILE_APPEND | LOCK_EX);
    }

    // ── Entry helpers ───────────────────────────────────────────────────────

    /**
     * Returns the best-quality markdown for the entry, auto-fetching when needed.
     * Returns null when entry_id is not found.
     *
     * @return string|null
     */
    private function getEntryMarkdown($entry_id)
    {
        $entry_dao = FreshRSS_Factory::createEntryDao();
        $entry     = $entry_dao->searchById($entry_id);
        if ($entry === null) {
            return null;
        }

        $content    = $entry->content();
        $articleUrl = method_exists($entry, 'link') ? (string)$entry->link() : '';

        return $this->makeContentProvider()->getMarkdown($content, $articleUrl);
    }

    // ── Actions ─────────────────────────────────────────────────────────────

    public function summarizeAction()
    {
        $this->view->_layout(false);

        $buttons = $this->loadButtons();
        $btn_idx = (int)(Minz_Request::param('btn') ?? 0);
        $button  = $buttons[$btn_idx] ?? null;

        if (empty($button)) {
            header('Content-Type: application/json');
            echo json_encode(['response' => ['data' => 'missing config', 'error' => 'configuration'], 'status' => 200]);
            return;
        }

        $oai_url    = trim($button->url);
        $oai_key    = trim($button->key);
        $oai_model  = trim($button->model);
        $oai_prompt = trim($button->prompt);

        if ($this->isEmpty($oai_url) || $this->isEmpty($oai_key) || $this->isEmpty($oai_model) || $this->isEmpty($oai_prompt)) {
            header('Content-Type: application/json');
            echo json_encode(['response' => ['data' => 'missing config', 'error' => 'configuration'], 'status' => 200]);
            return;
        }

        $entry_id        = Minz_Request::param('id');
        $markdownContent = $this->getEntryMarkdown($entry_id);

        if ($markdownContent === null) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 404]);
            return;
        }

        $oai_url = rtrim($oai_url, '/');
        if (!preg_match('/\/v\d+\/?$/', $oai_url)) {
            $oai_url .= '/v1';
        }

        // Support %rss_content% placeholder: embed article directly in prompt.
        // Without placeholder: system = prompt, user = article.
        if (strpos($oai_prompt, '%rss_content%') !== false) {
            $inputMessages = [
                ['role' => 'user', 'content' => str_replace('%rss_content%', $markdownContent, $oai_prompt)],
            ];
        } else {
            $inputMessages = [
                ['role' => 'system', 'content' => $oai_prompt],
                ['role' => 'user',   'content' => "input: \n" . $markdownContent],
            ];
        }

        $dto = new OpenAiRequestDto(
            $oai_url . '/responses',
            $oai_key,
            [
                'model'             => $oai_model,
                'input'             => $inputMessages,
                'reasoning'         => ['effort' => 'low'],
                'max_output_tokens' => 2048,
                'temperature'       => 1,
                'stream'            => true,
            ],
            180
        );

        $client      = $this->makeOpenAiClient();
        $headersSent = false;
        $finalStatus = 0;
        $errorBody   = '';

        $finalStatus = $client->stream(
            $dto,
            function (string $data, int $status, string $contentType) use (&$headersSent, &$finalStatus, &$errorBody) {
                $finalStatus = $status;
                if (!$headersSent) {
                    if ($status >= 200 && $status < 300) {
                        $this->prepareSseHeaders($contentType ?: 'text/event-stream');
                    } else {
                        header('Content-Type: application/json', true, $status ?: 500);
                    }
                    $headersSent = true;
                }
                if ($status >= 200 && $status < 300) {
                    echo $data;
                    flush();
                } else {
                    $errorBody .= $data;
                }
            }
        );

        if ($finalStatus < 200 || $finalStatus >= 300) {
            if (!$headersSent) {
                header('Content-Type: application/json', true, $finalStatus ?: 500);
            }
            $msg     = 'Summary request failed';
            $decoded = json_decode($errorBody, true);
            if ($decoded && isset($decoded['error']['message'])) {
                $msg = $decoded['error']['message'];
            }
            echo json_encode(['response' => ['data' => '', 'error' => $msg], 'status' => $finalStatus ?: 500]);
        }
    }

    /**
     * Returns the full article markdown as JSON.
     * Used by the JS article-level TTS button so it voices the same text
     * the summariser uses (auto-fetched when RSS content is too short).
     *
     * POST/GET params: id (entry id)
     */
    public function getArticleTextAction()
    {
        $this->view->_layout(false);
        header('Content-Type: application/json');

        $entry_id        = Minz_Request::param('id');
        $this->debugLog('getArticleTextAction called, entry_id=' . var_export($entry_id, true));
        $markdownContent = $this->getEntryMarkdown($entry_id);

        if ($markdownContent === null) {
            $this->debugLog('getArticleTextAction: entry not found');
            echo json_encode(['error' => 'Not found', 'status' => 404]);
            return;
        }

        $this->debugLog('getArticleTextAction: text.length=' . strlen($markdownContent));
        echo json_encode(['text' => $markdownContent, 'status' => 200]);
    }

    public function speakAction()
    {
        $this->view->_layout(false);
        // TTS can take 5-30 s per chunk; prevent PHP timeout from killing the request.
        set_time_limit(120);
        ignore_user_abort(true);
        $this->debugLog('speakAction called, content.len=' . strlen(trim((string)(Minz_Request::param('content') ?? ''))));

        // TTS connection: dedicated oai_tts_url/oai_tts_key, fallback to first button
        $tts_url = trim((string)(FreshRSS_Context::$user_conf->oai_tts_url ?? ''));
        $tts_key = trim((string)(FreshRSS_Context::$user_conf->oai_tts_key ?? ''));

        if ($this->isEmpty($tts_url) || $this->isEmpty($tts_key)) {
            $buttons = $this->loadButtons();
            $btn0    = $buttons[0] ?? null;
            if ($this->isEmpty($tts_url) && $btn0 !== null) {
                $tts_url = trim($btn0->url);
            }
            if ($this->isEmpty($tts_key) && $btn0 !== null) {
                $tts_key = trim($btn0->key);
            }
        }

        $tts_model = trim((string)(FreshRSS_Context::$user_conf->oai_tts_model ?? 'tts-1'));
        // Voice can be overridden per-request (e.g. different voice for result vs article)
        $voiceParam   = trim((string)(Minz_Request::param('voice') ?? ''));
        $voiceDefault = trim((string)(FreshRSS_Context::$user_conf->oai_voice ?? 'alloy'));
        $voice        = ($voiceParam !== '') ? $voiceParam : $voiceDefault;
        $speed     = FreshRSS_Context::$user_conf->oai_speed;
        if ($speed === null || !is_numeric($speed)) {
            $speed = 1.1;
        }
        $speed        = max(0.5, min(4.0, (float)$speed));
        $content      = trim((string)(Minz_Request::param('content') ?? ''));
        $articleTitle = trim((string)(Minz_Request::param('title') ?? ''));
        $format       = (string)(Minz_Request::param('format') ?? 'opus');
        $format       = in_array($format, ['mp3', 'ogg', 'opus']) ? $format : 'opus';

        if ($this->isEmpty($tts_url) || $this->isEmpty($tts_key) || $this->isEmpty($tts_model) || $this->isEmpty($voice) || $this->isEmpty($content)) {
            $this->debugLog('speakAction: missing config — tts_url=' . var_export($tts_url, true)
                . ' tts_key=' . (empty($tts_key) ? 'EMPTY' : 'SET')
                . ' tts_model=' . var_export($tts_model, true)
                . ' voice=' . var_export($voice, true)
                . ' content.len=' . strlen($content));
            header('Content-Type: application/json');
            echo json_encode(['response' => ['data' => 'missing config', 'error' => 'configuration'], 'status' => 200]);
            return;
        }

        $this->debugLog('speakAction: calling TtsService model=' . $tts_model . ' voice=' . $voice . ' content.len=' . strlen($content));

        $ttsService  = $this->makeTtsService($articleTitle);
        $headersSent = false;
        $finalStatus = 0;
        $errorBody   = '';

        $finalStatus = $ttsService->speak(
            $content,
            $tts_url,
            $tts_key,
            $tts_model,
            $voice,
            $speed,
            $format,
            function (string $data, int $status, string $contentType) use (&$headersSent, &$finalStatus, &$errorBody) {
                $finalStatus = $status;
                if (!$headersSent) {
                    if ($status >= 200 && $status < 300) {
                        header('Content-Type: ' . ($contentType ?: 'audio/ogg'));
                        header('Cache-Control: no-cache');
                        while (ob_get_level() > 0) {
                            ob_end_clean();
                        }
                        ob_implicit_flush(true);
                    } else {
                        header('Content-Type: application/json', true, $status ?: 500);
                    }
                    $headersSent = true;
                    $this->debugLog('speakAction: first chunk status=' . $status . ' content-type=' . $contentType);
                }
                if ($status >= 200 && $status < 300) {
                    echo $data;
                    flush();
                } else {
                    $errorBody .= $data;
                }
            },
            60
        );

        $this->debugLog('speakAction: done finalStatus=' . $finalStatus . ' errorBody.len=' . strlen($errorBody));

        if ($finalStatus < 200 || $finalStatus >= 300) {
            if (!$headersSent) {
                header('Content-Type: application/json', true, $finalStatus ?: 500);
            }
            $msg     = 'Audio request failed';
            $decoded = json_decode($errorBody, true);
            if ($decoded && isset($decoded['error']['message'])) {
                $msg = $decoded['error']['message'];
            }
            echo json_encode(['response' => ['data' => '', 'error' => $msg], 'status' => $finalStatus ?: 500]);
        }
    }

    /**
     * Disabled for security: previously leaked oai_key to the browser.
     */
    public function fetchTtsParamsAction()
    {
        $this->view->_layout(false);
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: application/json');
        echo json_encode(['error' => 'This endpoint is disabled for security reasons.']);
    }
}
