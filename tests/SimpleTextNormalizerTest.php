<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class SimpleTextNormalizerTest extends TestCase
{
    private SimpleTextNormalizer $n;

    protected function setUp(): void
    {
        $this->n = new SimpleTextNormalizer();
    }

    // ── Basic ─────────────────────────────────────────────────────────────────

    public function testEmptyStringReturnsEmpty(): void
    {
        $this->assertSame('', $this->n->normalize(''));
    }

    public function testPlainTextIsReturnedUnchanged(): void
    {
        $text = 'Hello world. This is a plain sentence.';
        $this->assertSame($text, $this->n->normalize($text));
    }

    // ── Markdown headers ──────────────────────────────────────────────────────

    public function testRemovesMarkdownHeaders(): void
    {
        $result = $this->n->normalize("## Заголовок второго уровня\nТекст.");
        $this->assertStringNotContainsString('##', $result);
        $this->assertStringContainsString('Заголовок второго уровня', $result);
        $this->assertStringContainsString('Текст', $result);
    }

    public function testRemovesAllHeaderLevels(): void
    {
        $result = $this->n->normalize("# H1\n## H2\n### H3\n#### H4");
        $this->assertStringNotContainsString('#', $result);
        $this->assertStringContainsString('H1', $result);
        $this->assertStringContainsString('H4', $result);
    }

    // ── Code ──────────────────────────────────────────────────────────────────

    public function testRemovesFencedCodeBlocks(): void
    {
        $result = $this->n->normalize("Текст\n```php\n\$x = 1;\n```\nКонец.");
        $this->assertStringNotContainsString('```', $result);
        $this->assertStringNotContainsString('$x', $result);
        $this->assertStringContainsString('Текст', $result);
        $this->assertStringContainsString('Конец', $result);
    }

    public function testRemovesInlineCodeButKeepsContent(): void
    {
        $result = $this->n->normalize('Метод `normalize` возвращает строку.');
        $this->assertStringNotContainsString('`', $result);
        $this->assertStringContainsString('normalize', $result);
    }

    // ── Emphasis ──────────────────────────────────────────────────────────────

    public function testRemovesAsteriskedBoldAndItalic(): void
    {
        $result = $this->n->normalize('**жирный** и *курсив* в тексте.');
        $this->assertStringNotContainsString('**', $result);
        $this->assertStringNotContainsString('*жирный*', $result);
        $this->assertStringContainsString('жирный', $result);
        $this->assertStringContainsString('курсив', $result);
    }

    public function testRemovesStrikethrough(): void
    {
        $result = $this->n->normalize('Обычный ~~удалённый~~ текст.');
        $this->assertStringNotContainsString('~~', $result);
        $this->assertStringContainsString('удалённый', $result);
    }

    // ── Links / Images ────────────────────────────────────────────────────────

    public function testRemovesMarkdownLinksButKeepsVisibleText(): void
    {
        $result = $this->n->normalize('[подробнее](https://example.com)');
        $this->assertStringNotContainsString('https://', $result);
        $this->assertStringNotContainsString('[', $result);
        $this->assertStringContainsString('подробнее', $result);
    }

    public function testRemovesMarkdownImagesButKeepsAltText(): void
    {
        $result = $this->n->normalize('![логотип компании](https://example.com/logo.png)');
        $this->assertStringNotContainsString('!', $result);
        $this->assertStringNotContainsString('https://', $result);
        $this->assertStringContainsString('логотип компании', $result);
    }

    public function testRemovesBareUrls(): void
    {
        $result = $this->n->normalize('Смотри https://example.com/page?q=1 для деталей.');
        $this->assertStringNotContainsString('https://', $result);
        $this->assertStringNotContainsString('example.com', $result);
        $this->assertStringContainsString('Смотри', $result);
        $this->assertStringContainsString('для деталей', $result);
    }

    // ── Lists / Blockquotes ───────────────────────────────────────────────────

    public function testRemovesBulletListMarkers(): void
    {
        $text   = "- пункт один\n- пункт два\n* пункт три";
        $result = $this->n->normalize($text);
        $this->assertStringContainsString('пункт один', $result);
        $this->assertStringContainsString('пункт три', $result);
        $this->assertDoesNotMatchRegularExpression('/^[-*]\s/m', $result);
    }

    public function testRemovesBlockquoteMarkers(): void
    {
        $result = $this->n->normalize("> цитата из текста\n> продолжение");
        $this->assertStringNotContainsString('>', $result);
        $this->assertStringContainsString('цитата', $result);
    }

    // ── Typography ────────────────────────────────────────────────────────────

    public function testReplacesEmDashWithCommaAndSpace(): void
    {
        $result = $this->n->normalize('Первое — второе.');
        $this->assertStringNotContainsString('—', $result);
        $this->assertStringContainsString(',', $result);
        $this->assertStringContainsString('Первое', $result);
        $this->assertStringContainsString('второе', $result);
    }

    // ── HTML ──────────────────────────────────────────────────────────────────

    public function testStripsHtmlTags(): void
    {
        $result = $this->n->normalize('<p>Параграф <strong>текст</strong></p>');
        $this->assertStringNotContainsString('<p>', $result);
        $this->assertStringNotContainsString('<strong>', $result);
        $this->assertStringContainsString('Параграф', $result);
        $this->assertStringContainsString('текст', $result);
    }

    public function testDecodesHtmlEntities(): void
    {
        $result = $this->n->normalize('Цена &lt;= 100&amp;более');
        $this->assertStringNotContainsString('&lt;', $result);
        $this->assertStringNotContainsString('&amp;', $result);
    }
}

