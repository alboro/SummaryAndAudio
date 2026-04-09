<?php

declare(strict_types=1);

/**
 * Rule-based text normalizer for text-to-speech pre-processing.
 *
 * Removes Markdown formatting, HTML tags, bare URLs and common typographic
 * symbols so the resulting string can be fed directly to a TTS engine without
 * unintended pronunciation of formatting characters.
 *
 * Based on widely-used TTS pre-processing patterns.
 * MIT License — part of alboro/SummaryAndAudio.
 */
class SimpleTextNormalizer implements TextNormalizerInterface
{
    public function normalize(string $text): string
    {
        if ($text === '') {
            return '';
        }

        // 1. Strip HTML tags
        $text = strip_tags($text);

        // 2. Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 3. Remove fenced code blocks  (``` ... ```)
        $text = preg_replace('/```[\s\S]*?```/u', '', $text) ?? $text;

        // 4. Remove inline code — keep the content without backticks
        $text = preg_replace('/`([^`]*)`/u', '$1', $text) ?? $text;

        // 5. Remove markdown images — keep the alt text
        $text = preg_replace('/!\[([^\]]*)\]\([^\)]*\)/u', '$1', $text) ?? $text;

        // 6. Remove markdown links — keep the visible link text
        $text = preg_replace('/\[([^\]]+)\]\([^\)]+\)/u', '$1', $text) ?? $text;

        // 7. Remove bare URLs
        $text = preg_replace('#https?://\S+#u', '', $text) ?? $text;

        // 8. Remove markdown headings  (# ## ### …)
        $text = preg_replace('/^#{1,6}\h+/mu', '', $text) ?? $text;

        // 9. Remove bold / italic markers  (** * __ _)
        $text = preg_replace('/(\*{1,3}|_{1,3})(.+?)\1/su', '$2', $text) ?? $text;

        // 10. Remove strikethrough  (~~text~~)
        $text = preg_replace('/~~(.+?)~~/su', '$1', $text) ?? $text;

        // 11. Remove blockquote markers  (> )
        $text = preg_replace('/^\h*>\h?/mu', '', $text) ?? $text;

        // 12. Remove table separator rows  (|---|---|)
        $text = preg_replace('/^\|[-: |]+\|$/mu', '', $text) ?? $text;

        // 13. Remove horizontal rules  (--- *** ___)
        $text = preg_replace('/^[-*_]{3,}\h*$/mu', '', $text) ?? $text;

        // 14. Remove unordered bullet markers
        $text = preg_replace('/^\h*[-*+]\h+/mu', '', $text) ?? $text;

        // 15. Remove ordered list markers  (1. 2) etc.)
        $text = preg_replace('/^\h*\d+[.)]\h+/mu', '', $text) ?? $text;

        // 16. Replace em/en dash with ", "
        $text = preg_replace('/\h*[—–]\h*/u', ', ', $text) ?? $text;

        // 17. Remove copyright / trademark symbols
        $text = str_replace(['©', '®', '™'], '', $text);

        // 18. Collapse runs of spaces/tabs to a single space
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;

        // 19. Collapse 3+ blank lines to two
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        // 20. Remove leading space on each line (artifact of previous steps)
        $text = preg_replace('/^ /mu', '', $text) ?? $text;

        return trim($text);
    }
}

