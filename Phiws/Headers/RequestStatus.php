<?php namespace Phiws\Headers;

class RequestStatus extends Status {
  protected $httpVersion;
  protected $method;
  protected $uri;

  static function from($header) {
    // GET /web/socket.io HTTP/1.1
    $regexp = '/^([A-Z]+) +(\S+) +HTTP\/(\d(?:\.\d+)?)$/i';

    if (!preg_match($regexp, rtrim($header), $match)) {
      \Phiws\StatusCodes\MalformedHttpHeader::fail("HTTP status line ($header): malformed");
    }

    list(, $method, $uri, $ver) = $match;
    return new static($method, $uri, $ver);
  }

  function __construct($method, $uri, $httpVersion = 1.1) {
    $this->method = strtoupper($method);
    $this->uri = trim($uri);
    $this->httpVersion = (float) $httpVersion;
  }

  function httpVersion() {
    return $this->httpVersion;
  }

  function method() {
    return $this->method;
  }

  function uri() {
    return $this->uri;
  }

  function path() {
    return parse_url($this->uri, PHP_URL_PATH);
  }

  function query() {
    return parse_url($this->query, PHP_URL_QUERY);
  }

  function parameters() {
    parse_str($this->query, $params);
    return (array) $params;
  }

  function parameter($name) {
    $params = $this->parameters();
    return isset($params[$name]) ? $params[$name] : null;
  }

  function join() {
    return "$this->method $this->uri HTTP/".$this->formattedHttpVersion();
  }
}
