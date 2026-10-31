<?php

declare(strict_types=1);

namespace FilippoToso\WireguardProxy {
    function header(string $header, bool $replace = true, int $responseCode = 0): void
    {
        \FilippoToso\WireguardProxy\Tests\Support\SentHeaders::add($header);
        \header($header, $replace, $responseCode);
    }
}

namespace {
    require dirname(__DIR__) . '/vendor/autoload.php';
}
