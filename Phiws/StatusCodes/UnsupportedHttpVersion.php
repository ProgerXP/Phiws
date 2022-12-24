<?php namespace Phiws\StatusCodes;

// "Use of WebSocket requires use of HTTP version 1.1 or higher."
class UnsupportedHttpVersion extends PreHandshakeCode {
  const TEXT = 'Unsupported HTTP Version';

  protected $httpCode = 505;
}
