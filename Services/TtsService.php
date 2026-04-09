<?php

declare(strict_types=1);

/**
 * Encapsulates the full TTS pipeline:
 *
 *   1. Normalize text via an injected TextNormalizerInterface.
 *   2. Stream audio bytes from an OpenAI-compatible /audio/speech endpoint
 *      via an injected OpenAiClientInterface.
 *
 * Both dependencies are injected, making the service fully unit-testable
 * without any HTTP calls.
 */
class TtsService
{
    private OpenAiClientInterface   $client;
    private TextNormalizerInterface $normalizer;

    public function __construct(
        OpenAiClientInterface   $client,
        TextNormalizerInterface $normalizer
    ) {
        $this->client     = $client;
        $this->normalizer = $normalizer;
    }

    /**
     * Normalize $text and stream TTS audio chunks to $onChunk.
     *
     * @param string   $text     Raw text to synthesize (will be normalized first).
     * @param string   $apiBase  Base URL of the TTS API (with or without /v1).
     * @param string   $apiKey   Bearer token.
     * @param string   $model    TTS model name (e.g. "tts-1").
     * @param string   $voice    Voice ID (e.g. "alloy", "echo").
     * @param float    $speed    Playback speed, clamped to [0.5, 4.0].
     * @param string   $format   Audio format: "mp3" | "ogg" | "opus".
     * @param callable $onChunk  function(string $data, int $status, string $contentType): void
     * @param int      $timeout  cURL timeout in seconds.
     *
     * @return int Final HTTP status (0 = connection failed entirely).
     */
    public function speak(
        string   $text,
        string   $apiBase,
        string   $apiKey,
        string   $model,
        string   $voice,
        float    $speed,
        string   $format,
        callable $onChunk,
        int      $timeout = 60
    ): int {
        $normalized = $this->normalizer->normalize($text);
        if (trim($normalized) === '') {
            $normalized = $text; // safety fallback — never send empty input to TTS
        }

        $dto = new OpenAiRequestDto(
            $this->buildEndpoint($apiBase),
            $apiKey,
            [
                'model'  => $model,
                'voice'  => $voice,
                'speed'  => $speed,
                'input'  => $normalized,
                'format' => $format,
            ],
            $timeout
        );

        return $this->client->stream($dto, $onChunk);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildEndpoint(string $apiBase): string
    {
        $base = rtrim($apiBase, '/');
        if (!preg_match('/\/v\d+$/', $base)) {
            $base .= '/v1';
        }
        return $base . '/audio/speech';
    }
}

