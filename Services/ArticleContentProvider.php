<?php

declare(strict_types=1);

/**
 * Provides the best-quality article markdown for a given RSS entry.
 *
 * When RSS content is too short (< 200 stripped chars) and an article URL is
 * available, the full page is fetched via the injected ArticleFetcherInterface.
 * This is the same logic used by both the summarize and TTS actions so that
 * both always operate on the same text.
 */
class ArticleContentProvider
{
    /** @var ArticleFetcherInterface */
    private $fetcher;

    /** @var HtmlToMarkdownConverter */
    private $converter;

    public function __construct(
        ArticleFetcherInterface $fetcher,
        HtmlToMarkdownConverter $converter
    ) {
        $this->fetcher   = $fetcher;
        $this->converter = $converter;
    }

    /**
     * Returns Markdown for the article.
     *
     * @param string $htmlContent RSS-cached HTML (may be truncated)
     * @param string $articleUrl  Original article URL used for auto-fetch
     */
    public function getMarkdown(string $htmlContent, string $articleUrl = ''): string
    {
        $plain = strip_tags($htmlContent);

        if (strlen($plain) < 200 && $articleUrl !== '') {
            $fetchedHtml = $this->fetcher->fetch($articleUrl);
            if ($fetchedHtml !== '') {
                $fetchedMd = $this->converter->convert($fetchedHtml);
                if (strlen($fetchedMd) > strlen($plain)) {
                    return $fetchedMd;
                }
            }
        }

        return $this->converter->convert($htmlContent);
    }
}

