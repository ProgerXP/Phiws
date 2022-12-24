<?php namespace Phiws\StatusCodes;

class UnsupportedWebSocketVersion extends PreHandshakeCode {
  const TEXT = 'Unsupported WebSocket Version';

  // Page 23.
  protected $httpCode = 426; 

  // Page 26, section 4.4:
  // "If the server doesn't support the requested version, it MUST respond with a |Sec-WebSocket-Version| header field (or multiple |Sec-WebSocket-Version| header fields) containing all versions it is willing to use."
  function httpErrorHeaders() {
    $headers = parent::httpErrorHeaders();
    $headers->add('Sec-Websocket-Version', \Phiws\BaseTunnel::WS_VERSION);
    return $headers;
  }
}
