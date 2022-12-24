<?php namespace Phiws\StatusCodes;

// "It is designated for use in applications expecting a status code to indicate that the connection was closed due to a failure to perform a TLS handshake (e.g., the server certificate can't be verified)."
// It's a reserved code but will never appear in a Close frame because TLS handshake
// is part of WebSocket handshake.
class TlsHandshakeFailed extends PreHandshakeCode {
  const CODE = 1015;
  const TEXT = 'TLS Handshake Failed';

  protected $httpCode = 412;
}
