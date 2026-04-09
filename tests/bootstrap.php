<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap — loads Composer autoloader (includes Services/ classmap)
 * and defines FreshRSS stubs required by the controller.
 *
 * Services tests do NOT need FreshRSS stubs; this file is harmless for them.
 */
require_once __DIR__ . '/../vendor/autoload.php';

// ── Minimal FreshRSS stubs ────────────────────────────────────────────────────

if (!class_exists('Minz_ActionController')) {
    class Minz_ActionController {}
}

if (!class_exists('FreshRSS_Context')) {
    class FreshRSS_Context
    {
        /** @var object|null */
        public static $user_conf;
    }
}

if (!class_exists('Minz_Request')) {
    class Minz_Request
    {
        /** @var array<string, mixed> */
        public static $params = [];

        /** @return mixed */
        public static function param(string $name)
        {
            return self::$params[$name] ?? null;
        }

        public static function isPost(): bool
        {
            return false;
        }
    }
}

if (!class_exists('FreshRSS_Factory')) {
    class FreshRSS_Factory
    {
        public static function createEntryDao(): object
        {
            return new class {
                public function searchById($id): ?object
                {
                    return null;
                }
            };
        }
    }
}

