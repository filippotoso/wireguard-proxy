<?php

declare(strict_types=1);

namespace FilippoToso\WireguardProxy;

use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;

final class Response
{
    private readonly ResponseInterface $message;

    public function __construct(ResponseInterface $upstream)
    {
        $body = (string) $upstream->getBody();
        $message = $upstream
            ->withoutHeader('Transfer-Encoding')
            ->withoutHeader('Connection')
            ->withoutHeader('Keep-Alive');
        if ($body === '') {
            $message = $message->withBody(Utils::streamFor(''))->withoutHeader('Content-Length');
        } else {
            $message = $message
                ->withBody(Utils::streamFor($body))
                ->withHeader('Content-Length', (string) strlen($body));
        }
        $this->message = $message;
    }

    /**
     * Emit this response to the current PHP client.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            $status = $this->message->getStatusCode();
            $reason = $this->message->getReasonPhrase();
            $line = 'HTTP/' . $this->message->getProtocolVersion() . ' ' . $status;
            if ($reason !== '') {
                $line .= ' ' . $reason;
            }
            header($this->singleLine($line), true, $status);
            foreach ($this->message->getHeaders() as $name => $values) {
                $name = $this->singleLine($name);
                foreach ($values as $index => $value) {
                    header($name . ': ' . $this->singleLine($value), $index === 0);
                }
            }
        }

        echo (string) $this->message->getBody();
    }

    private function singleLine(string $value): string
    {
        return str_replace(["\r", "\n"], '', $value);
    }
}
