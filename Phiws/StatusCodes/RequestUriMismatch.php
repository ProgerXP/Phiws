<?php namespace Phiws\StatusCodes;

// "If the requested service is not available, the server MUST send an appropriate HTTP error code (such as 404 Not Found) and abort the WebSocket handshake."
class RequestUriMismatch extends PreHandshakeCode {
  const TEXT = 'Request-URI Mismatch';

  protected $httpCode = 404;
}
