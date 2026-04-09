<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class ButtonConfigParserTest extends TestCase
{
    private ButtonConfigParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ButtonConfigParser();
    }

    public function testParsesValidJsonArrayToButtonConfigs(): void
    {
        $json = json_encode([
            ['label' => 'Summarize', 'url' => 'https://api.example.com', 'key' => 'sk-123', 'model' => 'gpt-4', 'prompt' => 'Summarize this'],
            ['label' => 'Analyze',   'url' => 'https://api.example.com', 'key' => 'sk-456', 'model' => 'gpt-4', 'prompt' => 'Analyze this'],
        ]);

        $buttons = $this->parser->parseJson($json);

        $this->assertCount(2, $buttons);
        $this->assertInstanceOf(ButtonConfig::class, $buttons[0]);
        $this->assertSame('Summarize', $buttons[0]->label);
        $this->assertSame('Analyze',   $buttons[1]->label);
        $this->assertSame('sk-123',    $buttons[0]->key);
        $this->assertSame('Summarize this', $buttons[0]->prompt);
    }

    public function testSkipsEntriesWithEmptyLabelAndEmptyPrompt(): void
    {
        $json = json_encode([
            ['label' => '', 'url' => 'u', 'key' => 'k', 'model' => 'm', 'prompt' => ''],
            ['label' => 'Good', 'url' => 'u', 'key' => 'k', 'model' => 'm', 'prompt' => 'p'],
        ]);

        $buttons = $this->parser->parseJson($json);

        $this->assertCount(1, $buttons);
        $this->assertSame('Good', $buttons[0]->label);
    }

    public function testKeepsEntryWithEmptyLabelButNonEmptyPrompt(): void
    {
        $json = json_encode([
            ['label' => '', 'url' => 'u', 'key' => 'k', 'model' => 'm', 'prompt' => 'some prompt'],
        ]);

        $buttons = $this->parser->parseJson($json);

        $this->assertCount(1, $buttons);
        $this->assertSame('some prompt', $buttons[0]->prompt);
    }

    public function testReturnsEmptyArrayForNonArrayJson(): void
    {
        $parseJsonCalls = 0;
        $assertions = function (string $input) use (&$parseJsonCalls) {
            $parseJsonCalls++;
            return $this->parser->parseJson($input);
        };

        $this->assertSame([], $assertions('not json at all'));
        $this->assertSame([], $assertions('"a string"'));
        $this->assertSame([], $assertions('42'));
        $this->assertSame([], $assertions('null'));
        $this->assertSame([], $assertions('{}'));
    }

    public function testParseLegacyWithTwoPrompts(): void
    {
        $buttons = $this->parser->parseLegacy('https://api.com', 'sk-key', 'gpt-4', 'prompt1', 'prompt2');

        $this->assertCount(2, $buttons);
        $this->assertSame('Summarize', $buttons[0]->label);
        $this->assertSame('+',         $buttons[1]->label);
        $this->assertSame('prompt1',   $buttons[0]->prompt);
        $this->assertSame('prompt2',   $buttons[1]->prompt);
        $this->assertSame('https://api.com', $buttons[0]->url);
        $this->assertSame('sk-key',    $buttons[0]->key);
    }

    public function testParseLegacyIgnoresEmptySecondPrompt(): void
    {
        $buttons = $this->parser->parseLegacy('u', 'k', 'm', 'prompt1', '');
        $this->assertCount(1, $buttons);
        $this->assertSame('Summarize', $buttons[0]->label);
    }

    public function testParseLegacyReturnsEmptyWhenBothPromptsEmpty(): void
    {
        $buttons = $this->parser->parseLegacy('u', 'k', 'm', '', '');
        $this->assertCount(0, $buttons);
    }

    public function testButtonConfigToArray(): void
    {
        $config = new ButtonConfig('Summarize', 'https://api.com', 'sk-key', 'gpt-4', 'prompt');
        $arr    = $config->toArray();

        $this->assertSame('Summarize',        $arr['label']);
        $this->assertSame('https://api.com',  $arr['url']);
        $this->assertSame('sk-key',           $arr['key']);
        $this->assertSame('gpt-4',            $arr['model']);
        $this->assertSame('prompt',           $arr['prompt']);
    }
}

