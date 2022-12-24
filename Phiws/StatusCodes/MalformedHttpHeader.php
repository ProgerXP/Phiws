<?php namespace Phiws\StatusCodes;

// For example, when Upgrade isn't "WebSocket".
//
// Text is typically set to "Header-Name: message".
class MalformedHttpHeader extends PreHandshakeCode {
  const TEXT = 'Malformed HTTP Header';

  protected $httpCode = 400;
}
