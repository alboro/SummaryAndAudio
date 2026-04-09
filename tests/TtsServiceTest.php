<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class TtsServiceTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function noopChunk(): callable
    {
        return static function (string $data, int $status, string $contentType): void {};
    }

    /** Returns a normalizer mock that echoes back the input unchanged. */
    private function passthroughNormalizer(): TextNormalizerInterface
    {
        $n = $this->createMock(TextNormalizerInterface::class);
        $n->method('normalize')->willReturnArgument(0);
        return $n;
    }

    /** Returns a client mock that immediately returns $status without emitting data. */
    private function silentClient(int $status = 200): OpenAiClientInterface
    {
        $client = $this->createMock(OpenAiClientInterface::class);
        $client->method('stream')->willReturn($status);
        return $client;
    }

    private function makeService(
        OpenAiClientInterface   $client,
        TextNormalizerInterface $normalizer
    ): TtsService {
        return new TtsService($client, $normalizer);
    }

    // ── Normalization ─────────────────────────────────────────────────────────

    public function testNormalizesTextBeforeSendingToTtsApi(): void
    {
        $normalizer = $this->createMock(TextNormalizerInterface::class);
        $normalizer->expects($this->once())
            ->method('normalize')
            ->with('**жирный** текст')
            ->willReturn('жирный текст');

        $capturedPayload = null;
        $client = $this->createMock(OpenAiClientInterface::class);
        $client->method('stream')
            ->willReturnCallback(function ($dto, $cb) use (&$capturedPayload): int {
                $capturedPayload = $dto->payload;
                return 200;
            });

        $this->makeService($client, $normalizer)
            ->speak('**жирный** текст', 'http://api.example.com', 'key', 'tts-1', 'alloy', 1.0, 'opus', $this->noopChunk());

        $this->assertSame('жирный текст', $capturedPayload['input']);
    }

    public function testFallsBackToOriginalTextWhenNormalizerReturnsEmpty(): void
    {
        $normalizer = $this->createMock(TextNormalizerInterface::class);
        $normalizer->method('normalize')->willReturn('');

        $capturedPayload = null;
        $client = $this->createMock(OpenAiClientInterface::class);
        $client->method('stream')
            ->willReturnCallback(function ($dto, $cb) use (&$capturedPayload): int {
                $capturedPayload = $dto->payload;
                return 200;
            });

        $this->makeService($client, $normalizer)
            ->speak('оригинальный текст', 'http://api.example.com', 'key', 'tts-1', 'alloy', 1.0, 'opus', $this->noopChunk());

        $this->assertSame('оригинальный текст', $capturedPayload['input']);
    }

    public function testFallsBackToOriginalTextWhenNormalizerReturnsWhitespace(): void
    {
        $normalizer = $this->createMock(TextNormalizerInterface::class);
        $normalizer->method('normalize')->willReturn('   ');

        $capturedPayload = null;
        $client = $this->createMock(OpenAiClientInterface::class);
        $client->method('stream')
            ->willReturnCallback(function ($dto, $cb) use (&$capturedPayload): int {
                $capturedPayload = $dto->payload;
                return 200;
            });

        $this->makeService($client, $normalizer)
            ->speak('исходный текст', 'http://api.example.com', 'key', 'tts-1', 'alloy', 1.0, 'opus', $this->noopChunk());

        $this->assertSame('исходный текст', $capturedPayload['input']);
    }

    // ── URL building ──────────────────────────────────────────────────────────

    public function testAppendsV1AndAudioSpeechToBaseUrl(): void
    {
        $capturedEndpoint = null;
        $client = $this->createMock(OpenAiClientInterface::class);
        $client->method('stream')
            ->willReturnCallback(function ($dto, $cb) use (&$capturedEndpoint): int {
                $capturedEndpoint = $dto->endpoint;
                return 200;
            });

        $this->makeService($client, $this->passthroughNormalizer())
            ->speak('text', 'http://tts.example.com', 'key', 'tts-1', 'alloy', 1.0, 'opus', $this->noopChunk());

        $this->assertSame('http://tts.example.com/v1/audio/speech', $capturedEndpoint);
    }

    public function testDoesNotDuplicateV1WhenAlreadyPresentInUrl(): void
    {
        $capturedEndpoint = null;
        $client = $this->createMock(OpenAiClientInterface::class);
        $client->method('stream')
            ->willReturnCallback(function ($dto, $cb) use (&$capturedEndpoint): int {
                $capturedEndpoint = $dto->endpoint;
                return 200;
            });

        $this->makeService($client, $this->passthroughNormalizer())
            ->speak('text', 'http://tts.example.com/v1', 'key', 'tts-1', 'alloy', 1.0, 'opus', $this->noopChunk());

        $this->assertSame('http://tts.example.com/v1/audio/speech', $capturedEndpoint);
    }

    // ── Request parameters ────────────────────────────────────────────────────

    public function testPassesAllTtsParametersCorrectly(): void
    {
        $normalizer = $this->createMock(TextNormalizerInterface::class);
        $normalizer->method('normalize')->willReturn('озвучиваемый текст');

        $capturedDto = null;
        $client = $this->createMock(OpenAiClientInterface::class);
        $client->method('stream')
            ->willReturnCallback(function ($dto, $cb) use (&$capturedDto): int {
                $capturedDto = $dto;
                return 200;
            });

        $this->makeService($client, $normalizer)->speak(
            'оригинал',
            'http://api.example.com',
            'my-api-key',
            'tts-1-hd',
            'nova',
            1.3,
            'mp3',
            $this->noopChunk(),
            90
        );

        $this->assertSame('my-api-key', $capturedDto->apiKey);
        $this->assertSame(90, $capturedDto->timeout);
        $this->assertSame('tts-1-hd', $capturedDto->payload['model']);
        $this->assertSame('nova', $capturedDto->payload['voice']);
        $this->assertSame(1.3, $capturedDto->payload['speed']);
        $this->assertSame('mp3', $capturedDto->payload['format']);
        $this->assertSame('озвучиваемый текст', $capturedDto->payload['input']);
    }

    // ── Return value / callbacks ──────────────────────────────────────────────

    public function testReturnsHttpStatusCodeFromClient(): void
    {
        $status = $this->makeService($this->silentClient(401), $this->passthroughNormalizer())
            ->speak('текст', 'http://api.example.com', 'key', 'tts-1', 'alloy', 1.0, 'opus', $this->noopChunk());

        $this->assertSame(401, $status);
    }

    public function testForwardsAudioChunksToCallback(): void
    {
        $client = $this->createMock(OpenAiClientInterface::class);
        $client->method('stream')
            ->willReturnCallback(static function ($dto, callable $onChunk): int {
                $onChunk('audio-chunk-1', 200, 'audio/opus');
                $onChunk('audio-chunk-2', 200, 'audio/opus');
                return 200;
            });

        $received = [];
        $this->makeService($client, $this->passthroughNormalizer())->speak(
            'текст', 'http://api.example.com', 'key', 'tts-1', 'alloy', 1.0, 'opus',
            function (string $data, int $status, string $ct) use (&$received): void {
                $received[] = [$data, $status, $ct];
            }
        );

        $this->assertCount(2, $received);
        $this->assertSame('audio-chunk-1', $received[0][0]);
        $this->assertSame('audio-chunk-2', $received[1][0]);
        $this->assertSame(200, $received[0][1]);
        $this->assertSame('audio/opus', $received[0][2]);
    }

    public function testReturnsZeroWhenConnectionFails(): void
    {
        $client = $this->createMock(OpenAiClientInterface::class);
        $client->method('stream')->willReturn(0);

        $status = $this->makeService($client, $this->passthroughNormalizer())
            ->speak('текст', 'http://api.example.com', 'key', 'tts-1', 'alloy', 1.0, 'opus', $this->noopChunk());

        $this->assertSame(0, $status);
    }
}

