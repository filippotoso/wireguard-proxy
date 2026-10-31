<?php

declare(strict_types=1);

namespace FilippoToso\WireguardProxy\Tests\Support;

final class SentHeaders
{
    /** @var list<string> */
    public static array $lines = [];

    public static function add(string $header): void
    {
        self::$lines[] = $header;
    }

    public static function reset(): void
    {
        self::$lines = [];
    }

    public static function contains(string $expected): bool
    {
        foreach (self::$lines as $line) {
            if (strtolower($line) === strtolower($expected)) {
                return true;
            }
        }

        return false;
    }
}
