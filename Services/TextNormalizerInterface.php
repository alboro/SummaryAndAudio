<?php

declare(strict_types=1);

/**
 * Contract for text normalizers that prepare text for text-to-speech synthesis.
 *
 * Implementations must clean up any formatting, special characters or markup
 * that would be rendered literally (and awkwardly) by a TTS engine.
 */
interface TextNormalizerInterface
{
    /**
     * Normalize $text so it can be safely passed to a TTS engine.
     * Returns the normalized text; must not return an empty string when
     * the input is non-empty (fall back to the original on failure).
     */
    public function normalize(string $text): string;
}

