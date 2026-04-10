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

    public function testParsesValidJsonArrayToAiButtons(): void
    {
        $json = json_encode([
            ['label' => 'Summarize', 'url' => 'https://api.example.com', 'key' => 'sk-123', 'model' => 'gpt-4', 'prompt' => 'Summarize this'],
            ['label' => 'Analyze',   'url' => 'https://api.example.com', 'key' => 'sk-456', 'model' => 'gpt-4', 'prompt' => 'Analyze this', 'effort' => 'high'],
        ]);

        $buttons = $this->parser->parseJson($json);

        $this->assertInstanceOf(AiButtonCollection::class, $buttons);
        $this->assertCount(2, $buttons);
        $this->assertInstanceOf(AiButton::class, $buttons->get(0));
        $this->assertSame('Summarize',      $buttons->get(0)->label);
        $this->assertSame('Analyze',        $buttons->get(1)->label);
        $this->assertSame('sk-123',         $buttons->get(0)->key);
        $this->assertSame('Summarize this', $buttons->get(0)->prompt);
        $this->assertSame('low',            $buttons->get(0)->effort); // default
        $this->assertSame('high',           $buttons->get(1)->effort);
    }

    public function testSkipsEntriesWithEmptyLabelAndEmptyPrompt(): void
    {
        $json = json_encode([
            ['label' => '', 'url' => 'u', 'key' => 'k', 'model' => 'm', 'prompt' => ''],
            ['label' => 'Good', 'url' => 'u', 'key' => 'k', 'model' => 'm', 'prompt' => 'p'],
        ]);

        $buttons = $this->parser->parseJson($json);

        $this->assertCount(1, $buttons);
        $this->assertSame('Good', $buttons->get(0)->label);
    }

    public function testKeepsEntryWithEmptyLabelButNonEmptyPrompt(): void
    {
        $json = json_encode([
            ['label' => '', 'url' => 'u', 'key' => 'k', 'model' => 'm', 'prompt' => 'some prompt'],
        ]);

        $buttons = $this->parser->parseJson($json);

        $this->assertCount(1, $buttons);
        $this->assertSame('some prompt', $buttons->get(0)->prompt);
    }

    public function testReturnsEmptyCollectionForNonArrayJson(): void
    {
        foreach (['not json at all', '"a string"', '42', 'null', '{}'] as $input) {
            $result = $this->parser->parseJson($input);
            $this->assertInstanceOf(AiButtonCollection::class, $result, "Input: $input");
            $this->assertTrue($result->isEmpty(), "Expected empty collection for input: $input");
        }
    }

    public function testParseLegacyWithTwoPrompts(): void
    {
        $buttons = $this->parser->parseLegacy('https://api.com', 'sk-key', 'gpt-4', 'prompt1', 'prompt2');

        $this->assertInstanceOf(AiButtonCollection::class, $buttons);
        $this->assertCount(2, $buttons);
        $this->assertSame('Summarize',       $buttons->get(0)->label);
        $this->assertSame('+',               $buttons->get(1)->label);
        $this->assertSame('prompt1',         $buttons->get(0)->prompt);
        $this->assertSame('prompt2',         $buttons->get(1)->prompt);
        $this->assertSame('https://api.com', $buttons->get(0)->url);
        $this->assertSame('sk-key',          $buttons->get(0)->key);
        $this->assertSame('low',             $buttons->get(0)->effort); // default
    }

    public function testParseLegacyIgnoresEmptySecondPrompt(): void
    {
        $buttons = $this->parser->parseLegacy('u', 'k', 'm', 'prompt1', '');
        $this->assertCount(1, $buttons);
        $this->assertSame('Summarize', $buttons->get(0)->label);
    }

    public function testParseLegacyReturnsEmptyWhenBothPromptsEmpty(): void
    {
        $buttons = $this->parser->parseLegacy('u', 'k', 'm', '', '');
        $this->assertCount(0, $buttons);
        $this->assertTrue($buttons->isEmpty());
    }

    public function testAiButtonToArrayContainsAllFields(): void
    {
        $btn = new AiButton('Summarize', 'https://api.com', 'sk-key', 'gpt-4', 'prompt', 'high', 'echo');
        $arr = $btn->toArray();

        $this->assertSame('Summarize',       $arr[ButtonField::LABEL]);
        $this->assertSame('https://api.com', $arr[ButtonField::URL]);
        $this->assertSame('sk-key',          $arr[ButtonField::KEY]);
        $this->assertSame('gpt-4',           $arr[ButtonField::MODEL]);
        $this->assertSame('prompt',          $arr[ButtonField::PROMPT]);
        $this->assertSame('high',            $arr[ButtonField::EFFORT]);
        $this->assertSame('echo',            $arr[ButtonField::VOICE]);
    }

    public function testAiButtonNormalisesInvalidEffortToLow(): void
    {
        $btn = new AiButton('L', 'u', 'k', 'm', 'p', 'turbo'); // invalid effort
        $this->assertSame('low', $btn->effort);
    }

    public function testAiButtonFromArrayRoundTrip(): void
    {
        $original = new AiButton('Test', 'http://x', 'key', 'model', 'do it', 'medium', 'alloy');
        $restored = AiButton::fromArray($original->toArray());

        $this->assertNotNull($restored);
        $this->assertSame($original->label,  $restored->label);
        $this->assertSame($original->effort, $restored->effort);
        $this->assertSame($original->voice,  $restored->voice);
    }

    public function testAiButtonCollectionIterableWithForeach(): void
    {
        $buttons = $this->parser->parseLegacy('u', 'k', 'm', 'p1', 'p2');
        $labels  = [];
        foreach ($buttons as $btn) {
            $labels[] = $btn->label;
        }
        $this->assertSame(['Summarize', '+'], $labels);
    }

    // ── backward-compat alias ────────────────────────────────────────────────

    public function testButtonConfigIsAliasForAiButton(): void
    {
        $bc = new ButtonConfig('Summarize', 'https://api.com', 'sk-key', 'gpt-4', 'prompt');
        $this->assertInstanceOf(AiButton::class, $bc);
        $this->assertSame('low', $bc->effort);
    }
}
