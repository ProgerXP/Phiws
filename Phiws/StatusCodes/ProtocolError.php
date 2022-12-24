<?php namespace Phiws\StatusCodes;

class ProtocolError extends \Phiws\StatusCode {
  const CODE = 1002;
  const TEXT = 'Protocol Error';

  protected $httpCode = 400;
}
