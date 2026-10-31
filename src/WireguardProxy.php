<?php

declare(strict_types=1);

namespace FilippoToso\WireguardProxy;

use FilippoToso\WireguardTransport\Client;
use FilippoToso\WireguardTransport\WireguardTransport;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

final class WireguardProxy
{
    private const HOP_BY_HOP = [
        'Connection',
        'Keep-Alive',
        'Proxy-Authenticate',
        'Proxy-Authorization',
        'TE',
        'Trailers',
        'Transfer-Encoding',
        'Upgrade',
    ];

    private function __construct(private readonly Client $client)
    {
    }

    public static function withConfigFile(string $path): self
    {
        return new self(WireguardTransport::withConfigFile($path));
    }

    public static function withConfig(string $contents): self
    {
        return new self(WireguardTransport::withConfig($contents));
    }

    /**
     * Send the method, headers, and body of $request to $url through the tunnel.
     * $request is a PSR-7 request. Create one from the current PHP request with
     * GuzzleHttp\Psr7\ServerRequest::fromGlobals().
     *
     * When $remapPath is true, the target URL is a base. The path under the
     * script directory is appended to it. An incoming query string replaces
     * the query string on the target URL; otherwise that query string is kept.
     */
    public function forward(string $url, RequestInterface $request, bool $remapPath = false): Response
    {
        $uri = new Uri($url);
        $scheme = strtolower($uri->getScheme());
        if (($scheme !== 'http' && $scheme !== 'https') || $uri->getHost() === '') {
            throw new \InvalidArgumentException('The proxy target must be an absolute http(s) URL.');
        }
        if ($remapPath) {
            $uri = $this->remap($uri, $request);
        }

        $forwarded = $request->withUri($uri);
        foreach (self::HOP_BY_HOP as $name) {
            $forwarded = $forwarded->withoutHeader($name);
        }
        $body = $forwarded->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return new Response($this->client->sendRequest($forwarded));
    }

    public function disconnect(): void
    {
        $this->client->disconnect();
    }

    private function remap(UriInterface $base, RequestInterface $request): UriInterface
    {
        $query = $request->getUri()->getQuery();
        if ($query === '') {
            $query = $base->getQuery();
        }

        return $base
            ->withPath($this->joinPath($base->getPath(), $this->forwardedPath($request)))
            ->withQuery($query);
    }

    /**
     * The path to append. The script directory is removed when the incoming
     * path starts with it. Otherwise the incoming path is kept whole.
     */
    private function forwardedPath(RequestInterface $request): string
    {
        $path = $request->getUri()->getPath();
        if ($path === '') {
            $path = '/';
        }
        if (!$request instanceof ServerRequestInterface) {
            return $path;
        }

        $script = $request->getServerParams()['SCRIPT_NAME'] ?? '';
        if (!is_string($script) || $script === '') {
            return $path;
        }

        $script = str_replace('\\', '/', $script);
        $stripped = $this->stripPrefix($path, $script);
        if ($stripped !== null) {
            return $stripped;
        }

        $directory = str_replace('\\', '/', dirname($script));

        return $this->stripPrefix($path, $directory) ?? $path;
    }

    private function stripPrefix(string $path, string $prefix): ?string
    {
        $prefix = rtrim($prefix, '/');
        if ($prefix === '' || $prefix === '.' || $prefix === '/') {
            return null;
        }
        if ($path === $prefix) {
            return '/';
        }
        if (str_starts_with($path, $prefix . '/')) {
            $rest = substr($path, strlen($prefix));

            return $rest === '' ? '/' : $rest;
        }

        return null;
    }

    private function joinPath(string $basePath, string $relative): string
    {
        if ($relative === '' || $relative === '/') {
            return $basePath === '' ? '/' : $basePath;
        }
        if (!str_starts_with($relative, '/')) {
            $relative = '/' . $relative;
        }
        if ($basePath === '' || $basePath === '/') {
            return $relative;
        }

        return rtrim($basePath, '/') . $relative;
    }
}
