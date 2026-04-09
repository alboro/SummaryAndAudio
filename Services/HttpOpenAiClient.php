<?php

declare(strict_types=1);

/**
 * cURL-backed implementation of OpenAiClientInterface.
 * Not final so it can be subclassed in integration tests if needed.
 */
class HttpOpenAiClient implements OpenAiClientInterface
{
    public function stream(OpenAiRequestDto $dto, callable $onChunk): int
    {
        $statusCode      = 0;
        $respContentType = '';

        $ch = curl_init($dto->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => $dto->timeout,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $dto->apiKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($dto->payload),
            CURLOPT_HEADERFUNCTION => function ($curl, $header) use (&$statusCode, &$respContentType) {
                $len = strlen($header);
                if (preg_match('#HTTP/\d+(?:\.\d+)?\s+(\d+)#', $header, $m)) {
                    $statusCode = (int)$m[1];
                } elseif (stripos($header, 'Content-Type:') === 0) {
                    $respContentType = trim(substr($header, 13));
                }
                return $len;
            },
            CURLOPT_WRITEFUNCTION  => function ($curl, $data) use (&$statusCode, &$respContentType, $onChunk) {
                $onChunk($data, $statusCode, $respContentType);
                return strlen($data);
            },
        ]);

        curl_exec($ch);
        curl_close($ch);

        return $statusCode;
    }
}

