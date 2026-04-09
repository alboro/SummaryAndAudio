<?php

declare(strict_types=1);

/**
 * Parses the oai_buttons JSON config (or legacy flat-key config) into ButtonConfig objects.
 *
 * All button-config parsing logic lives here so the controller and extension
 * share a single source of truth.
 */
class ButtonConfigParser
{
    /**
     * Parse a JSON string produced by handleConfigureAction / ext_setup.php.
     *
     * @return ButtonConfig[]
     */
    public function parseJson(string $json): array
    {
        $arr = json_decode($json, true);
        if (!is_array($arr)) {
            return [];
        }

        $result = [];
        foreach ($arr as $item) {
            if (!is_array($item)) {
                continue;
            }
            $btn = $this->parseOne($item);
            if ($btn !== null) {
                $result[] = $btn;
            }
        }

        return $result;
    }

    /**
     * Build ButtonConfig list from the old flat config keys.
     * Used as a fallback when oai_buttons is not set.
     *
     * @return ButtonConfig[]
     */
    public function parseLegacy(
        string $url,
        string $key,
        string $model,
        string $prompt1,
        string $prompt2 = ''
    ): array {
        $buttons = [];

        if (trim($prompt1) !== '') {
            $buttons[] = new ButtonConfig('Summarize', $url, $key, $model, $prompt1);
        }

        if (trim($prompt2) !== '') {
            $buttons[] = new ButtonConfig('+', $url, $key, $model, $prompt2);
        }

        return $buttons;
    }

    private function parseOne(array $data): ?ButtonConfig
    {
        $label  = trim((string)($data['label']  ?? ''));
        $url    = trim((string)($data['url']    ?? ''));
        $key    = trim((string)($data['key']    ?? ''));
        $model  = trim((string)($data['model']  ?? ''));
        $prompt = trim((string)($data['prompt'] ?? ''));

        if ($label === '' && $prompt === '') {
            return null;
        }

        return new ButtonConfig($label, $url, $key, $model, $prompt);
    }
}

