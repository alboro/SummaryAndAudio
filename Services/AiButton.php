<?php

declare(strict_types=1);

/**
 * Value object representing a single configured AI action button.
 *
 * New fields vs the old ButtonConfig:
 *   • effort  — reasoning effort: 'low' | 'medium' | 'high' (default 'low')
 *   • voice   — optional per-button TTS voice override; '' = use global default
 *
 * Use AiButton::fromArray() to create from a plain associative array
 * (e.g. from json_decode or form input).
 */
class AiButton
{
    /** Valid values for the reasoning effort field. */
    const VALID_EFFORTS  = ['low', 'medium', 'high'];
    const DEFAULT_EFFORT = 'low';

    /** @var string */
    public $label;
    /** @var string */
    public $url;
    /** @var string */
    public $key;
    /** @var string */
    public $model;
    /** @var string */
    public $prompt;
    /** @var string 'low' | 'medium' | 'high' */
    public $effort;
    /** @var string optional TTS voice override; '' = use global default */
    public $voice;

    public function __construct(
        string $label,
        string $url,
        string $key,
        string $model,
        string $prompt,
        string $effort = self::DEFAULT_EFFORT,
        string $voice  = ''
    ) {
        $this->label  = $label;
        $this->url    = $url;
        $this->key    = $key;
        $this->model  = $model;
        $this->prompt = $prompt;
        $this->effort = in_array($effort, self::VALID_EFFORTS, true) ? $effort : self::DEFAULT_EFFORT;
        $this->voice  = $voice;
    }

    /**
     * Build an AiButton from a plain associative array (e.g. from json_decode or form data).
     * Uses ButtonField constants as array keys.
     * Returns null when both label and prompt are empty (invalid button).
     */
    public static function fromArray(array $data): ?self
    {
        $label  = trim((string)($data[ButtonField::LABEL]  ?? ''));
        $url    = trim((string)($data[ButtonField::URL]    ?? ''));
        $key    = trim((string)($data[ButtonField::KEY]    ?? ''));
        $model  = trim((string)($data[ButtonField::MODEL]  ?? ''));
        $prompt = trim((string)($data[ButtonField::PROMPT] ?? ''));
        $effort = trim((string)($data[ButtonField::EFFORT] ?? self::DEFAULT_EFFORT));
        $voice  = trim((string)($data[ButtonField::VOICE]  ?? ''));

        if ($label === '' && $prompt === '') {
            return null;
        }

        return new self($label, $url, $key, $model, $prompt, $effort, $voice);
    }

    /**
     * Convert to a plain array suitable for JSON serialisation.
     * All ButtonField keys are always present.
     */
    public function toArray(): array
    {
        return [
            ButtonField::LABEL  => $this->label,
            ButtonField::URL    => $this->url,
            ButtonField::KEY    => $this->key,
            ButtonField::MODEL  => $this->model,
            ButtonField::PROMPT => $this->prompt,
            ButtonField::EFFORT => $this->effort,
            ButtonField::VOICE  => $this->voice,
        ];
    }
}

