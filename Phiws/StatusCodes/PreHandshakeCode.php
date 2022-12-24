<?php namespace Phiws\StatusCodes;

// Base class for exceptions generated during/prior to handshake completion.
class PreHandshakeCode extends \Phiws\StatusCode {
  const TEXT = 'WebSocket Handshake Error';
}
