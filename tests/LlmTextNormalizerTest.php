<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class LlmTextNormalizerTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeNormalizer(
        OpenAiClientInterface $client,
        string $apiBase = 'http://api.example.com',
        string $prompt  = 'системный промпт'
    ): LlmTextNormalizer {
        return new LlmTextNormalizer($client, $apiBase, 'test-key', 'gpt-4', $prompt);
    }

    /**
     * Creates a mock client that calls $onChunk once with $jsonBody at $status.
     */
    private function mockClientReturning(string $jsonBody, int $status = 200): OpenAiClientInterface
    {
        $client = $this->createMock(OpenAiClientInterface::class);
        $client->method('stream')
            ->willReturnCallback(static function ($dto, callable $onChunk) use ($jsonBody, $status): int {
                $onChunk($jsonBody, $status, 'application/json');
                return $status;
            });
        return $client;
    }

    private function chatResponse(string $content): string
    {
        return json_encode([
            'choices' => [['message' => ['content' => $content]]],
        ]);
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    public function testReturnsNormalizedTextFromLlmResponse(): void
    {
        $client = $this->mockClientReturning($this->chatResponse('нормализованный текст'));

        $result = $this->makeNormalizer($client)->normalize('**жирный** текст');
        $this->assertSame('нормализованный текст', $result);
    }

    public function testTrimsLeadingAndTrailingWhitespaceFromLlmResult(): void
    {
        $client = $this->mockClientReturning($this->chatResponse("  результат  \n"));

        $result = $this->makeNormalizer($client)->normalize('текст');
        $this->assertSame('результат', $result);
    }

    // ── Fallback behaviour ────────────────────────────────────────────────────

    public function testFallsBackToOriginalTextOnApiError(): void
    {
        $client = $this->mockClientReturning('{"error":"server error"}', 500);

        $original = '**жирный** текст';
        $this->assertSame($original, $this->makeNormalizer($client)->normalize($original));
    }

    public function testFallsBackToOriginalOnMalformedJson(): void
    {
        $client = $this->mockClientReturning('not json at all');

        $original = 'некоторый текст';
        $this->assertSame($original, $this->makeNormalizer($client)->normalize($original));
    }

    public function testFallsBackToOriginalWhenLlmReturnsEmptyContent(): void
    {
        $client = $this->mockClientReturning($this->chatResponse(''));

        $original = 'важный текст';
        $this->assertSame($original, $this->makeNormalizer($client)->normalize($original));
    }

    public function testReturnsEmptyStringImmediatelyWithoutCallingApi(): void
    {
        $client = $this->createMock(OpenAiClientInterface::class);
        $client->expects($this->never())->method('stream');

        $this->assertSame('', $this->makeNormalizer($client)->normalize(''));
    }

    public function testReturnsWhitespaceOnlyStringWithoutCallingApi(): void
    {
        $client = $this->createMock(OpenAiClientInterface::class);
        $client->expects($this->never())->method('stream');

        $this->assertSame('   ', $this->makeNormalizer($client)->normalize('   '));
    }

    // ── Request payload ───────────────────────────────────────────────────────

    public function testSendsSystemPromptAndUserText(): void
    {
        $captured = null;
        $client   = $this->createMock(OpenAiClientInterface::class);
        $client->method('stream')
            ->willReturnCallback(function ($dto, callable $onChunk) use (&$captured): int {
                $captured = $dto->payload;
                $onChunk($this->chatResponse('ok'), 200, 'application/json');
                return 200;
            });

        $this->makeNormalizer($client, 'http://api.example.com', 'мой промпт')
            ->normalize('входящий текст');

        $this->assertNotNull($captured);
        $this->assertSame('system', $captured['messages'][0]['role']);
        $this->assertSame('мой промпт', $captured['messages'][0]['content']);
        $this->assertSame('user', $captured['messages'][1]['role']);
        $this->assertSame('входящий текст', $captured['messages'][1]['content']);
        $this->assertFalse($captured['stream']);
        $this->assertSame(0, $captured['temperature']);
    }

    // ── URL building ──────────────────────────────────────────────────────────

    public function testAppendsV1AndChatCompletionsToBaseUrl(): void
    {
        $capturedEndpoint = null;
        $client = $this->createMock(OpenAiClientInterface::class);
        $client->method('stream')
            ->willReturnCallback(function ($dto, callable $onChunk) use (&$capturedEndpoint): int {
                $capturedEndpoint = $dto->endpoint;
                $onChunk($this->chatResponse('ok'), 200, 'application/json');
                return 200;
            });

        $normalizer = new LlmTextNormalizer(
            $client, 'http://api.example.com', 'key', 'gpt-4', 'prompt'
        );
        $normalizer->normalize('текст');

        $this->assertSame('http://api.example.com/v1/chat/completions', $capturedEndpoint);
    }

    public function testDoesNotDuplicateV1WhenAlreadyPresent(): void
    {
        $capturedEndpoint = null;
        $client = $this->createMock(OpenAiClientInterface::class);
        $client->method('stream')
            ->willReturnCallback(function ($dto, callable $onChunk) use (&$capturedEndpoint): int {
                $capturedEndpoint = $dto->endpoint;
                $onChunk($this->chatResponse('ok'), 200, 'application/json');
                return 200;
            });

        $normalizer = new LlmTextNormalizer(
            $client, 'http://api.example.com/v1', 'key', 'gpt-4', 'prompt'
        );
        $normalizer->normalize('текст');

        $this->assertSame('http://api.example.com/v1/chat/completions', $capturedEndpoint);
    }

    // ── Chunked response ──────────────────────────────────────────────────────

    public function testReassemblesResponseFromMultipleChunks(): void
    {
        // Simulates a streaming response split across two cURL write callbacks
        $part1 = '{"choices":[{"message":{"content":';
        $part2 = '"результат"}}]}';

        $client = $this->createMock(OpenAiClientInterface::class);
        $client->method('stream')
            ->willReturnCallback(static function ($dto, callable $onChunk) use ($part1, $part2): int {
                $onChunk($part1, 200, 'application/json');
                $onChunk($part2, 200, 'application/json');
                return 200;
            });

        $result = $this->makeNormalizer($client)->normalize('текст');
        $this->assertSame('результат', $result);
    }
}

