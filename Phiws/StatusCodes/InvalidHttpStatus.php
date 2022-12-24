<?php namespace Phiws\StatusCodes;

// When server has returned something else than 101 Switching Protocols.
class InvalidHttpStatus extends PreHandshakeCode {
  const TEXT = 'Invalid HTTP Status';

  protected $httpCode = 500;
}
