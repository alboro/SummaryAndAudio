<?php

declare(strict_types=1);

interface ArticleFetcherInterface
{
    /**
     * Fetches raw HTML content from the given URL.
     * Returns empty string on failure.
     */
    public function fetch(string $url): string;
}

