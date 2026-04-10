<?php

declare(strict_types=1);

/**
 * Parses the oai_buttons JSON config (or legacy flat-key config)
 * into an AiButtonCollection.
 *
 * All button-config parsing logic lives here so the controller and extension
 * share a single source of truth.
 */
class ButtonConfigParser
{
    /**
     * Parse a JSON string produced by handleConfigureAction / ext_setup.php.
     */
    public function parseJson(string $json): AiButtonCollection
    {
        return AiButtonCollection::fromJson($json);
    }

    /**
     * Build an AiButtonCollection from the old flat config keys.
     * Used as a fallback when oai_buttons is not set.
     */
    public function parseLegacy(
        string $url,
        string $key,
        string $model,
        string $prompt1,
        string $prompt2 = ''
    ): AiButtonCollection {
        $buttons = [];

        if (trim($prompt1) !== '') {
            $buttons[] = new AiButton('Summarize', $url, $key, $model, $prompt1);
        }

        if (trim($prompt2) !== '') {
            $buttons[] = new AiButton('+', $url, $key, $model, $prompt2);
        }

        return new AiButtonCollection($buttons);
    }
}

