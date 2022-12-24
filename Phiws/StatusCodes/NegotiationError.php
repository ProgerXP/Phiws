<?php namespace Phiws\StatusCodes;

class NegotiationError extends PreHandshakeCode {
  const TEXT = 'Extension Negotiation Error';

  protected $httpCode = 400;
}
