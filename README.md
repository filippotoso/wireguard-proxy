# WireGuard Proxy

Forward one HTTP request to a URL inside a WireGuard tunnel, then send that response back to the caller.

The tunnel comes from `filippo-toso/wireguard-transport`. This process speaks WireGuard itself. Nothing here needs root, a TUN device, or a separate WireGuard binary.

## Requirements

- PHP 8.2 or newer, with the openssl and sodium extensions

## Install

```bash
composer require filippo-toso/wireguard-proxy
```

## Usage

The incoming request is a PSR-7 request. `ServerRequest::fromGlobals()` builds one from the request PHP is handling now.

```php
use FilippoToso\WireguardProxy\WireguardProxy;
use GuzzleHttp\Psr7\ServerRequest;

$proxy = WireguardProxy::withConfigFile(__DIR__ . '/wg0.conf');

$incoming = ServerRequest::fromGlobals();

$response = $proxy->forward('http://192.168.0.15:8080/endpoint', $incoming);

$response->send();
```

`forward()` sends the incoming method, headers, and body to that exact URL. The incoming request URI is not appended. Hop-by-hop headers such as `Connection` and `Transfer-Encoding` are not forwarded. Redirects from the target are returned as-is, so `send()` can pass a 302 to the caller.

`withConfig()` accepts the configuration text instead of a file. `disconnect()` drops the tunnel.

## Expose a path inside the tunnel

Pass `remapPath: true` when this script should stand in for an application inside the tunnel. The target URL is then a base, and the path under this script's directory is appended to it.

Put `index.php` in a directory such as `/proxy`, and route the rest of that directory to the script. A file that already exists there, including `index.php`, is left to the web server. Every other path is handled by the script:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [QSA,L]
```

```php
$response = $proxy->forward(
    'http://192.168.0.15:8080',
    ServerRequest::fromGlobals(),
    remapPath: true,
);

$response->send();
```

A request to `https://example.com/proxy/api/users?page=2` is sent to `http://192.168.0.15:8080/api/users?page=2`. The `/proxy` prefix comes from `SCRIPT_NAME`: it is the directory of this script, so a script at the site root forwards the whole path. A request to `/proxy/` itself is sent to the target URL unchanged.

A path on the target URL is kept. `forward('http://192.168.0.15:8080/app', ..., remapPath: true)` sends that same request to `http://192.168.0.15:8080/app/api/users?page=2`. The query string comes from the incoming request. When the incoming request has no query string, a query string on the target URL is kept.

## Limitations

- Traffic inside the tunnel is IPv4. IPv6 destinations and IPv6 WireGuard endpoints are rejected.
- TLS is 1.2, terminated by OpenSSL.
- HTTP is 1.1.
- The response body is buffered before `send()`.
