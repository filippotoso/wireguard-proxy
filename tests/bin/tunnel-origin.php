#!/usr/bin/env php
<?php

declare(strict_types=1);

use FilippoToso\WireguardTransport\Config\WireguardConfig;
use FilippoToso\WireguardTransport\Exception\TransportException;
use FilippoToso\WireguardTransport\Http\TunnelByteStream;
use FilippoToso\WireguardTransport\Tunnel\UserspaceTunnel;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$configPath = $argv[1] ?? '';
$readyPath = $argv[2] ?? '';
$recordPath = $argv[3] ?? '';
$certificate = $argv[4] ?? '';
$key = $argv[5] ?? '';

$tunnel = UserspaceTunnel::fromConfig(WireguardConfig::fromFile($configPath));
$tunnel->listen(80);
$tunnel->listen(443);
$tunnel->handleUdp(53, static function (string $query): ?string {
    if (strlen($query) < 12) {
        return null;
    }
    $question = substr($query, 12);
    $answer = "\xC0\x0C" . pack('nnNn', 1, 1, 60, 4) . inet_pton('10.0.0.2');

    return substr($query, 0, 2) . pack('nnnnn', 0x8180, 1, 1, 0, 0) . $question . $answer;
});
file_put_contents($readyPath, (string) $tunnel->localPort());
fwrite(STDERR, "listening {$tunnel->localPort()}\n");

while (true) {
    try {
        $connection = $tunnel->accept(microtime(true) + 60);
    } catch (TransportException) {
        exit(0);
    }

    try {
        $deadline = microtime(true) + 30;
        $stream = $connection->localPort === 443
            ? TunnelByteStream::tlsServer($tunnel, $connection, $certificate, $key, $deadline)
            : TunnelByteStream::plain($tunnel, $connection);
        $request = readRequest($stream, $deadline);
        $response = answer($tunnel, $request);
        $stream->write($response, $deadline);
        $stream->close();
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . "\n");
    } finally {
        try {
            $tunnel->closeTcp($connection, microtime(true) + 0.5);
        } catch (Throwable) {
            // The peer already went away.
        }
    }
}

/**
 * @return array{method: string, path: string, query: string, body: string, headers: array<string, string>}
 */
function readRequest(TunnelByteStream $stream, float $deadline): array
{
    $buffer = '';
    while (!str_contains($buffer, "\r\n\r\n")) {
        $chunk = $stream->read(8192, $deadline);
        if ($chunk === '') {
            throw new RuntimeException('The HTTP request ended early.');
        }
        $buffer .= $chunk;
        if (strlen($buffer) > 65536) {
            throw new RuntimeException('The HTTP request is too large.');
        }
    }
    [$head, $rest] = explode("\r\n\r\n", $buffer, 2);
    $lines = explode("\r\n", $head);
    $requestLine = array_shift($lines);
    if (!is_string($requestLine) || preg_match('#^([A-Z]+) (\S+) HTTP/\d(?:\.\d)?$#', $requestLine, $matches) !== 1) {
        throw new RuntimeException('Invalid HTTP request.');
    }
    $target = $matches[2];
    $path = parse_url($target, PHP_URL_PATH);
    $query = parse_url($target, PHP_URL_QUERY);
    $headers = [];
    foreach ($lines as $line) {
        $colon = strpos($line, ':');
        if ($colon === false) {
            continue;
        }
        $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
    }
    $length = isset($headers['content-length']) ? (int) $headers['content-length'] : 0;
    while (strlen($rest) < $length) {
        $chunk = $stream->read($length - strlen($rest), $deadline);
        if ($chunk === '') {
            throw new RuntimeException('The HTTP request body ended early.');
        }
        $rest .= $chunk;
    }

    return [
        'method' => $matches[1],
        'path' => is_string($path) && $path !== '' ? $path : '/',
        'query' => is_string($query) ? $query : '',
        'body' => substr($rest, 0, $length),
        'headers' => $headers,
    ];
}

/**
 * @param array{method: string, path: string, query: string, body: string, headers: array<string, string>} $request
 */
function answer(UserspaceTunnel $tunnel, array $request): string
{
    global $recordPath;
    if (is_string($recordPath) && $recordPath !== '') {
        file_put_contents($recordPath, json_encode([
            'method' => $request['method'],
            'path' => $request['path'],
            'query' => $request['query'],
            'body' => $request['body'],
            'headers' => $request['headers'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }

    $query = [];
    parse_str($request['query'], $query);
    if ($request['path'] === '/flaky') {
        $count = flakyCount();
        if ($count < 3) {
            return respond(503, ['Content-Type' => 'text/plain'], 'unavailable');
        }
    }
    if ($request['path'] === '/slow') {
        $seconds = isset($query['seconds']) ? (float) $query['seconds'] : 1.0;
        $tunnel->wait(max(0.0, $seconds));
    }
    if ($request['path'] === '/status') {
        $code = isset($query['code']) ? (int) $query['code'] : 200;

        return respond($code, ['Content-Type' => 'application/json'], json_encode(['status' => $code], JSON_THROW_ON_ERROR));
    }
    if ($request['path'] === '/empty') {
        return respond(204, [], '');
    }
    if ($request['path'] === '/redirect') {
        return respond(302, ['Location' => '/hello'], '');
    }

    return respond(200, ['Content-Type' => 'application/json'], json_encode([
        'ok' => true,
        'method' => $request['method'],
        'path' => $request['path'],
        'query' => $request['query'],
        'body' => $request['body'],
        'headers' => $request['headers'],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

function flakyCount(): int
{
    static $count = 0;
    $count++;

    return $count;
}

/**
 * @param array<string, string> $headers
 */
function respond(int $status, array $headers, string $body): string
{
    $reason = [
        200 => 'OK',
        204 => 'No Content',
        302 => 'Found',
        404 => 'Not Found',
        500 => 'Internal Server Error',
        503 => 'Service Unavailable',
    ][$status] ?? 'OK';
    $message = "HTTP/1.1 {$status} {$reason}\r\n";
    foreach ($headers as $name => $value) {
        $message .= $name . ': ' . $value . "\r\n";
    }
    if ($body !== '') {
        $message .= 'Content-Length: ' . strlen($body) . "\r\n";
    }
    $message .= "Connection: close\r\n\r\n" . $body;

    return $message;
}
