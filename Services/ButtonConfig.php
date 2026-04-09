<?php

declare(strict_types=1);

/**
 * Value object representing a single configured action button.
 * Not final so tests can extend if needed, but treat as immutable.
 */
class ButtonConfig
{
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

    public function __construct(
        string $label,
        string $url,
        string $key,
        string $model,
        string $prompt
    ) {
        $this->label  = $label;
        $this->url    = $url;
        $this->key    = $key;
        $this->model  = $model;
        $this->prompt = $prompt;
    }

    /** Convert to plain array for JSON serialisation. */
    public function toArray(): array
    {
        return [
            'label'  => $this->label,
            'url'    => $this->url,
            'key'    => $this->key,
            'model'  => $this->model,
            'prompt' => $this->prompt,
        ];
    }
}

