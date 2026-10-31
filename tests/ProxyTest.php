<?php

declare(strict_types=1);

namespace FilippoToso\WireguardProxy\Tests;

use FilippoToso\WireguardProxy\Response;
use FilippoToso\WireguardProxy\Tests\Support\SentHeaders;
use FilippoToso\WireguardProxy\Tests\Support\TunnelOrigin;
use FilippoToso\WireguardProxy\WireguardProxy;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

final class ProxyTest extends TestCase
{
    private static TunnelOrigin $origin;

    private static WireguardProxy $proxy;

    public static function setUpBeforeClass(): void
    {
        self::$origin = TunnelOrigin::start();
        self::$proxy = WireguardProxy::withConfig(self::$origin->clientConfig);
    }

    public static function tearDownAfterClass(): void
    {
        self::$proxy->disconnect();
        self::$origin->stop();
    }

    protected function setUp(): void
    {
        SentHeaders::reset();
    }

    public function test_an_incoming_request_is_forwarded_to_the_target_url(): void
    {
        $incoming = new ServerRequest(
            'POST',
            'http://app.test/ignored?unused=1',
            [
                'Content-Type' => 'application/json',
                'X-Trace' => 'abc',
                'Connection' => 'keep-alive',
            ],
            '{"name":"Ada"}',
        );

        $response = self::$proxy->forward(self::$origin->url('/items'), $incoming);
        $payload = $this->sentPayload($response);

        self::assertSame(200, http_response_code());
        self::assertSame('POST', $payload['method']);
        self::assertSame('/items', $payload['path']);
        self::assertSame('{"name":"Ada"}', $payload['body']);
        self::assertSame('', $payload['query']);
        self::assertSame('abc', $payload['headers']['x-trace']);
        self::assertSame('application/json', $payload['headers']['content-type']);
        self::assertSame('close', $payload['headers']['connection']);
        self::assertTrue(SentHeaders::contains('content-type: application/json'));
    }

    public function test_a_redirect_from_the_target_is_sent_back_unchanged(): void
    {
        $incoming = new ServerRequest('GET', 'http://app.test/ignored');
        $response = self::$proxy->forward(self::$origin->url('/redirect'), $incoming);

        ob_start();
        $response->send();
        ob_end_clean();

        self::assertSame(302, http_response_code());
        self::assertTrue(SentHeaders::contains('HTTP/1.1 302 Found'));
        self::assertTrue(SentHeaders::contains('location: /hello'));
    }

    public function test_remap_path_appends_the_path_under_the_script_directory_to_the_target_url(): void
    {
        $incoming = new ServerRequest(
            'GET',
            'http://public.test/proxy/api/users?q=ada',
            serverParams: ['SCRIPT_NAME' => '/proxy/index.php'],
        );

        $payload = $this->sentPayload(self::$proxy->forward(self::$origin->url('/'), $incoming, remapPath: true));

        self::assertSame('GET', $payload['method']);
        self::assertSame('/api/users', $payload['path']);
        self::assertSame('q=ada', $payload['query']);
    }

    public function test_remap_path_keeps_a_path_and_query_on_the_target_url(): void
    {
        $incoming = new ServerRequest(
            'POST',
            'http://public.test/proxy/health',
            ['Content-Type' => 'text/plain'],
            'ping',
            serverParams: ['SCRIPT_NAME' => '/proxy/index.php'],
        );

        $payload = $this->sentPayload(
            self::$proxy->forward('http://wg-http.invalid/app?token=1', $incoming, remapPath: true),
        );

        self::assertSame('POST', $payload['method']);
        self::assertSame('/app/health', $payload['path']);
        self::assertSame('token=1', $payload['query']);
        self::assertSame('ping', $payload['body']);
    }

    public function test_remap_path_uses_the_target_url_for_the_script_directory_itself(): void
    {
        $incoming = new ServerRequest(
            'GET',
            'http://public.test/proxy/',
            serverParams: ['SCRIPT_NAME' => '/proxy/index.php'],
        );

        $payload = $this->sentPayload(
            self::$proxy->forward('http://wg-http.invalid/app/', $incoming, remapPath: true),
        );

        self::assertSame('/app/', $payload['path']);
        self::assertSame('', $payload['query']);
    }

    public function test_remap_path_strips_the_script_name_when_the_request_uri_includes_it(): void
    {
        $incoming = new ServerRequest(
            'GET',
            'http://public.test/proxy/index.php/reports?year=2026',
            serverParams: ['SCRIPT_NAME' => '/proxy/index.php'],
        );

        $payload = $this->sentPayload(
            self::$proxy->forward('http://wg-http.invalid', $incoming, remapPath: true),
        );

        self::assertSame('/reports', $payload['path']);
        self::assertSame('year=2026', $payload['query']);
    }

    public function test_remap_path_prefers_the_incoming_query_over_the_query_on_the_target_url(): void
    {
        $incoming = new ServerRequest(
            'GET',
            'http://public.test/proxy/search?q=ada',
            serverParams: ['SCRIPT_NAME' => '/proxy/index.php'],
        );

        $payload = $this->sentPayload(
            self::$proxy->forward('http://wg-http.invalid/app?token=1', $incoming, remapPath: true),
        );

        self::assertSame('/app/search', $payload['path']);
        self::assertSame('q=ada', $payload['query']);
    }

    public function test_remap_path_does_not_strip_a_directory_that_only_shares_a_prefix(): void
    {
        $incoming = new ServerRequest(
            'GET',
            'http://public.test/proxymore/item',
            serverParams: ['SCRIPT_NAME' => '/proxy/index.php'],
        );

        $payload = $this->sentPayload(
            self::$proxy->forward('http://wg-http.invalid', $incoming, remapPath: true),
        );

        self::assertSame('/proxymore/item', $payload['path']);
    }

    public function test_a_config_file_opens_the_proxy(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'wgproxy-cfg-');
        if ($path === false) {
            self::fail('Could not create a config file.');
        }
        file_put_contents($path, self::$origin->clientConfig);
        $proxy = WireguardProxy::withConfigFile($path);

        try {
            $response = $proxy->forward(
                self::$origin->url('/hello'),
                new ServerRequest('GET', 'http://app.test/elsewhere'),
            );
            $payload = $this->sentPayload($response);
            self::assertSame('/hello', $payload['path']);
        } finally {
            $proxy->disconnect();
            unlink($path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function sentPayload(Response $response): array
    {
        ob_start();
        $response->send();
        $body = ob_get_clean();
        $payload = json_decode(is_string($body) ? $body : '', true);
        self::assertIsArray($payload);

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}
