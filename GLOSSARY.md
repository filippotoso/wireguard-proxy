# WireGuard Proxy

Forwards an HTTP request to one URL through a WireGuard tunnel.

## Language

**WireGuard configuration**:
The wg-quick description of one interface and the peers it connects to.
_Avoid_: profile, vpn settings, tunnel file

**Tunnel**:
A live WireGuard session this library can send traffic through.
_Avoid_: connection, interface, adapter

**Incoming request**:
The PSR-7 request the proxy was asked to forward.
_Avoid_: input, globals, superglobal

**Target URL**:
The absolute URL inside the tunnel that receives the incoming request.
_Avoid_: backend, upstream host

**Remap path**:
Treat the target URL as a base and append the incoming request's path under the script directory.
_Avoid_: base url, rewrite, ngrok

**Response**:
The HTTP response that came back from the target URL, ready to send to the caller.
_Avoid_: result, reply
