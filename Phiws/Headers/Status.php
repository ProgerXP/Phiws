<?php namespace Phiws\Headers;

abstract class Status {
  // PHP >= 5.3 < 7.0 throws E_STRICT: "Static function should not be abstract".
  static function from($header) {
    \Phiws\CodeException::fail('from: not implemented');
  }

  abstract function httpVersion();
  abstract function join();

  function __toString() {
    return $this->join();
  }

  function formattedHttpVersion() {
    $ver = rtrim(sprintf('%f', $this->httpVersion()), '0');
    substr($ver, -1) === '.' and $ver .= '0';
    return $ver;
  }
}
