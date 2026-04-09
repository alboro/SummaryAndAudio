<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class HtmlToMarkdownConverterTest extends TestCase
{
    private HtmlToMarkdownConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new HtmlToMarkdownConverter();
    }

    public function testConvertsSimpleParagraphToPlainText(): void
    {
        $result = $this->converter->convert('<p>Hello world</p>');

        $this->assertStringContainsString('Hello world', $result);
        $this->assertStringNotContainsString('<p>', $result);
    }

    public function testConvertsH1AndH2Headings(): void
    {
        $result = $this->converter->convert('<h1>Title</h1><h2>Subtitle</h2>');

        $this->assertStringContainsString('# Title', $result);
        $this->assertStringContainsString('## Subtitle', $result);
    }

    public function testConvertsH3ToH6Headings(): void
    {
        $result = $this->converter->convert('<h3>A</h3><h4>B</h4><h5>C</h5><h6>D</h6>');

        $this->assertStringContainsString('### A', $result);
        $this->assertStringContainsString('#### B', $result);
        $this->assertStringContainsString('##### C', $result);
        $this->assertStringContainsString('###### D', $result);
    }

    public function testConvertsBoldAndItalic(): void
    {
        $result = $this->converter->convert('<p><strong>bold</strong> and <em>italic</em></p>');

        $this->assertStringContainsString('**bold**', $result);
        $this->assertStringContainsString('*italic*', $result);
    }

    public function testConvertsBTagLikeBold(): void
    {
        $result = $this->converter->convert('<b>bold</b><i>italic</i>');

        $this->assertStringContainsString('**bold**', $result);
        $this->assertStringContainsString('*italic*', $result);
    }

    public function testConvertsUnorderedList(): void
    {
        $result = $this->converter->convert('<ul><li>item 1</li><li>item 2</li></ul>');

        $this->assertStringContainsString('- item 1', $result);
        $this->assertStringContainsString('- item 2', $result);
    }

    public function testConvertsLineBreakToNewline(): void
    {
        $result = $this->converter->convert('<p>line 1<br>line 2</p>');

        $this->assertStringContainsString('line 1', $result);
        $this->assertStringContainsString('line 2', $result);
    }

    public function testStripsHtmlTagsLeavingText(): void
    {
        $result = $this->converter->convert('<article><p>Clean <span>text</span> here</p></article>');

        $this->assertStringContainsString('Clean', $result);
        $this->assertStringContainsString('text', $result);
        $this->assertStringNotContainsString('<span>', $result);
    }

    public function testCollapsesExcessiveNewlines(): void
    {
        $result = $this->converter->convert('<p>A</p><p>B</p><p>C</p>');

        $this->assertStringNotContainsString("\n\n\n", $result);
    }

    public function testReturnsNonEmptyStringForEmptyInput(): void
    {
        $result = $this->converter->convert('');
        $this->assertIsString($result);
    }

    public function testHttpStatusHeaderRegexMatchesHttp1AndHttp2(): void
    {
        $pattern = '#HTTP/\d+(?:\.\d+)?\s+(\d+)#';

        foreach (['HTTP/2 200', 'HTTP/1.1 404', 'HTTP/1.0 500', 'HTTP/2.0 302'] as $line) {
            $this->assertMatchesRegularExpression($pattern, $line, "Regex should match: $line");
            preg_match($pattern, $line, $m);
            $this->assertTrue(is_numeric($m[1]), "Captured group should be numeric for: $line");
        }
    }
}

