<?php namespace Phiws\Headers;

class ResponseStatus extends Status {
  const SWITCHING = "Switching Protocols";
  
  protected $httpVersion;
  protected $code;
  protected $text;

  static function from($header) {
    // HTTP/1.1 101 Switching Protocols
    $regexp = '/^HTTP\/(\d(?:\.\d+)?) +(\d{3}) +(.+)$/i';

    if (!preg_match($regexp, rtrim($header), $match)) {
      \Phiws\StatusCodes\MalformedHttpHeader::fail("HTTP status line ($header): malformed");
    }

    list(, $ver, $code, $text) = $match;
    return new static($code, $text, $ver);
  }

  function __construct($code, $text, $httpVersion = 1.1) {
    $this->code = (int) $code;
    $this->text = trim($text);
    $this->httpVersion = (float) $httpVersion;
  }

  function httpVersion() {
    return $this->httpVersion;
  }

  function code() {
    return $this->code;
  }

  function text() {
    return $this->text;
  }

  function join() {
    return sprintf('HTTP/%s %03d %s', $this->formattedHttpVersion(), $this->code, $this->text);
  }
}
