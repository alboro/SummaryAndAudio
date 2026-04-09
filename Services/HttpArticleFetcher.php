<?php

declare(strict_types=1);

class HttpArticleFetcher implements ArticleFetcherInterface
{
    public function fetch(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; FreshRSS)',
            CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml'],
        ]);
        $html = curl_exec($ch);
        curl_close($ch);

        return (string)($html ?: '');
    }
}

