<?php

declare(strict_types=1);

/**
 * LLM-backed text normalizer for text-to-speech pre-processing.
 *
 * Calls an OpenAI-compatible /chat/completions endpoint with a system
 * prompt that instructs the model to strip formatting and special
 * characters from the input text.
 *
 * Falls back to the original text on any API / parsing error so TTS
 * always receives some input.
 */
class LlmTextNormalizer implements TextNormalizerInterface
{
    private OpenAiClientInterface $client;
    private string $apiBase;
    private string $apiKey;
    private string $model;
    private string $systemPrompt;
    private int $timeout;

    public function __construct(
        OpenAiClientInterface $client,
        string $apiBase,
        string $apiKey,
        string $model,
        string $systemPrompt,
        int    $timeout = 30
    ) {
        $this->client       = $client;
        $this->apiBase      = $apiBase;
        $this->apiKey       = $apiKey;
        $this->model        = $model;
        $this->systemPrompt = $systemPrompt;
        $this->timeout      = $timeout;
    }

    public function normalize(string $text): string
    {
        if (trim($text) === '') {
            return $text;
        }

        $endpoint = $this->buildEndpoint();
        $body     = '';

        $dto = new OpenAiRequestDto(
            $endpoint,
            $this->apiKey,
            [
                'model'       => $this->model,
                'stream'      => false,
                'messages'    => [
                    ['role' => 'system', 'content' => $this->systemPrompt],
                    ['role' => 'user',   'content' => $text],
                ],
                'temperature' => 0,
            ],
            $this->timeout
        );

        $status = $this->client->stream($dto, static function (string $chunk) use (&$body): void {
            $body .= $chunk;
        });

        if ($status < 200 || $status >= 300) {
            return $text; // fall back on API error
        }

        $data    = json_decode($body, true);
        $content = $data['choices'][0]['message']['content'] ?? '';

        return ($content !== '') ? trim($content) : $text;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildEndpoint(): string
    {
        $base = rtrim($this->apiBase, '/');
        if (!preg_match('/\/v\d+$/', $base)) {
            $base .= '/v1';
        }
        return $base . '/chat/completions';
    }
}

