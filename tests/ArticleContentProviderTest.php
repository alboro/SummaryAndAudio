<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ArticleContentProviderTest extends TestCase
{
    public function testReturnsConvertedHtmlWhenContentIsLongEnough(): void
    {
        $fetcher = $this->createMock(ArticleFetcherInterface::class);
        $fetcher->expects($this->never())->method('fetch');

        $provider = new ArticleContentProvider($fetcher, new HtmlToMarkdownConverter());

        $longText = str_repeat('Lorem ipsum dolor sit amet. ', 15);
        $html     = '<p>' . $longText . '</p>';
        $result   = $provider->getMarkdown($html, 'https://example.com/article');

        $this->assertStringContainsString('Lorem ipsum', $result);
    }

    public function testFetchesFullArticleWhenRssContentTooShort(): void
    {
        $fullHtml = '<p>' . str_repeat('Full article content from web. ', 20) . '</p>';

        $fetcher = $this->createMock(ArticleFetcherInterface::class);
        $fetcher->expects($this->once())
            ->method('fetch')
            ->with('https://example.com/article')
            ->willReturn($fullHtml);

        $provider = new ArticleContentProvider($fetcher, new HtmlToMarkdownConverter());

        $result = $provider->getMarkdown('<p>Short.</p>', 'https://example.com/article');

        $this->assertStringContainsString('Full article content from web.', $result);
    }

    public function testSkipsFetchWhenArticleUrlIsEmpty(): void
    {
        $fetcher = $this->createMock(ArticleFetcherInterface::class);
        $fetcher->expects($this->never())->method('fetch');

        $provider = new ArticleContentProvider($fetcher, new HtmlToMarkdownConverter());

        $result = $provider->getMarkdown('<p>Short.</p>', '');
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('Short', $result);
    }

    public function testFallsBackToOriginalWhenFetchedMarkdownIsShorter(): void
    {
        $counter = 0;
        $fetcher = $this->createMock(ArticleFetcherInterface::class);
        $fetcher->expects($this->once())
            ->method('fetch')
            ->willReturnCallback(function () use (&$counter) {
                $counter++;
                return '<p>X</p>';
            });

        $provider = new ArticleContentProvider($fetcher, new HtmlToMarkdownConverter());

        $originalHtml = '<p>Short but not empty enough to be replaced by X.</p>';
        $result       = $provider->getMarkdown($originalHtml, 'https://example.com');

        $this->assertStringContainsString('Short but not empty', $result);
        $this->assertSame(1, $counter);
    }

    public function testAutoFetchThresholdIsExactly200Chars(): void
    {
        $fetcher = $this->createMock(ArticleFetcherInterface::class);

        $provider = new ArticleContentProvider($fetcher, new HtmlToMarkdownConverter());

        // 200-char plain text — should NOT trigger fetch
        $text200     = str_repeat('A', 200);
        $html200     = '<p>' . $text200 . '</p>';
        $fetcher->expects($this->never())->method('fetch');
        $provider->getMarkdown($html200, 'https://example.com');
    }
}

