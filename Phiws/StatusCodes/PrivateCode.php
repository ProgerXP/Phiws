<?php namespace Phiws\StatusCodes;

class PrivateCode extends \Phiws\StatusCode {
  const START = 4000;
  const END = 4999;
  const CODE = 4000;
  const TEXT = 'Private Status Code';

  protected $code;

  function __construct($text = '', $wsCode = null, object $previous = null) {
    parent::__construct($text, null, $previous);

    $this->code = isset($wsCode) ? (int) $wsCode : static::CODE;

    if ($this->code < static::START or $this->code > static::END) {
      \Phiws\CodeException::fail("PrivateCode($wsCode): code is out of range");
    }
  }

  function code() {
    return $this->code;
  }
}
