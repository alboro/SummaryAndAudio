<?php

declare(strict_types=1);

/**
 * String-constant "enum" for AiButton field keys.
 * PHP-7.4-compatible alternative to PHP-8.1 native enums.
 *
 * Usage:  $data[ButtonField::EFFORT]  instead of  $data['effort']
 */
final class ButtonField
{
    const LABEL  = 'label';
    const URL    = 'url';
    const KEY    = 'key';
    const MODEL  = 'model';
    const PROMPT = 'prompt';
    const EFFORT = 'effort';
    const VOICE  = 'voice';

    /** All known field keys — useful for white-listing / serialisation. */
    const ALL = [
        self::LABEL,
        self::URL,
        self::KEY,
        self::MODEL,
        self::PROMPT,
        self::EFFORT,
        self::VOICE,
    ];

    private function __construct() {} // static-only helper class
}

