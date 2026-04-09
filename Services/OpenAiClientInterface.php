<?php

declare(strict_types=1);

/**
 * Streams a request to an OpenAI-compatible REST endpoint.
 *
 * $onChunk(string $data, int $httpStatus, string $contentType) is called for
 * every raw response chunk received. $httpStatus and $contentType reflect the
 * upstream HTTP response and are available from the very first call.
 *
 * Returns the final HTTP status code (0 if the connection failed entirely).
 */
interface OpenAiClientInterface
{
    /**
     * @param callable(string $data, int $httpStatus, string $contentType): void $onChunk
     */
    public function stream(OpenAiRequestDto $dto, callable $onChunk): int;
}

