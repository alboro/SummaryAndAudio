<?php

declare(strict_types=1);

/**
 * Data transfer object for a single OpenAI-compatible API request.
 */
class OpenAiRequestDto
{
    /** @var string Full endpoint URL, e.g. https://api.openai.com/v1/responses */
    public $endpoint;

    /** @var string Bearer token */
    public $apiKey;

    /** @var array Request payload (will be JSON-encoded) */
    public $payload;

    /** @var int cURL timeout in seconds */
    public $timeout;

    public function __construct(
        string $endpoint,
        string $apiKey,
        array  $payload,
        int    $timeout = 60
    ) {
        $this->endpoint = $endpoint;
        $this->apiKey   = $apiKey;
        $this->payload  = $payload;
        $this->timeout  = $timeout;
    }
}

