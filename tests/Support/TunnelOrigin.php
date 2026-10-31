<?php

declare(strict_types=1);

namespace FilippoToso\WireguardProxy\Tests\Support;

final class TunnelOrigin
{
    public readonly string $clientConfig;

    private function __construct(
        private readonly BackgroundProcess $process,
        private readonly string $directory,
        private readonly string $record,
        string $clientConfig,
    ) {
        $this->clientConfig = $clientConfig;
    }

    public static function start(): self
    {
        $directory = sys_get_temp_dir() . '/wghttp-origin-' . bin2hex(random_bytes(4));
        if (!mkdir($directory) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create the origin directory.');
        }

        $clientSecret = random_bytes(32);
        $serverSecret = random_bytes(32);
        $preshared = random_bytes(32);
        $clientPublic = sodium_crypto_scalarmult_base($clientSecret);
        $serverPublic = sodium_crypto_scalarmult_base($serverSecret);
        $certificate = $directory . '/cert.pem';
        $key = $directory . '/key.pem';
        $command = sprintf(
            'openssl req -x509 -newkey rsa:2048 -keyout %s -out %s -days 1 -nodes -subj /CN=wg-http.invalid 2>/dev/null',
            escapeshellarg($key),
            escapeshellarg($certificate),
        );
        exec($command, $output, $exitCode);
        if ($exitCode !== 0 || !is_file($certificate) || !is_file($key)) {
            throw new \RuntimeException('openssl could not create a certificate.');
        }

        $serverConfig = $directory . '/server.conf';
        file_put_contents($serverConfig, self::config($serverSecret, $clientPublic, $preshared, '10.0.0.2/32', '10.0.0.1/32', '127.0.0.1:1', null));
        $ready = $directory . '/ready';
        $record = $directory . '/record';
        file_put_contents($record, '');
        $log = $directory . '/origin.log';
        $process = new BackgroundProcess();
        $process->start(
            [PHP_BINARY, dirname(__DIR__) . '/bin/tunnel-origin.php', $serverConfig, $ready, $record, $certificate, $key],
            [],
            $log,
        );

        $deadline = microtime(true) + 5;
        $port = 0;
        while (microtime(true) < $deadline) {
            if (is_file($ready)) {
                $port = (int) trim((string) file_get_contents($ready));
                if ($port > 0) {
                    break;
                }
            }
            usleep(20_000);
        }
        if ($port === 0) {
            $process->stop();
            $logged = is_file($log) ? (string) file_get_contents($log) : '';
            throw new \RuntimeException("WireGuard origin did not start.\n" . $logged);
        }

        return new self(
            $process,
            $directory,
            $record,
            self::config($clientSecret, $serverPublic, $preshared, '10.0.0.1/32', '0.0.0.0/0', '127.0.0.1:' . $port, '10.0.0.2'),
        );
    }

    public function url(string $path): string
    {
        return 'http://wg-http.invalid' . '/' . ltrim($path, '/');
    }

    public function httpsUrl(string $path = '/'): string
    {
        return 'https://wg-http.invalid' . '/' . ltrim($path, '/');
    }

    public function mark(): int
    {
        $size = filesize($this->record);

        return is_int($size) ? $size : 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function requestsSince(int $offset): array
    {
        $raw = file_get_contents($this->record);
        if (!is_string($raw)) {
            return [];
        }
        $requests = [];
        foreach (explode("\n", substr($raw, $offset)) as $line) {
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $requests[] = $decoded;
            }
        }

        return $requests;
    }

    public function log(): string
    {
        $path = $this->directory . '/origin.log';

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    public function stop(): void
    {
        $this->process->stop();
        if (!is_dir($this->directory)) {
            return;
        }
        foreach (scandir($this->directory) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $path = $this->directory . '/' . $file;
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($this->directory);
    }

    private static function config(
        string $secret,
        string $peerPublic,
        string $preshared,
        string $address,
        string $allowedIps,
        string $endpoint,
        ?string $dns,
    ): string {
        $dnsLine = $dns === null ? '' : "DNS = {$dns}\n";

        return "[Interface]\n"
            . 'PrivateKey = ' . base64_encode($secret) . "\n"
            . "Address = {$address}\n"
            . $dnsLine
            . "\n[Peer]\n"
            . 'PublicKey = ' . base64_encode($peerPublic) . "\n"
            . 'PresharedKey = ' . base64_encode($preshared) . "\n"
            . "AllowedIPs = {$allowedIps}\n"
            . "Endpoint = {$endpoint}\n";
    }
}
