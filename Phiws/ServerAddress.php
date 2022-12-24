<?php namespace Phiws;

// Section 3: WebSocket URIs.
class ServerAddress {
  protected $secure = false;
  protected $host;
  protected $port;
  protected $path = '';
  protected $query = '';

  function __construct($host, $port) {
    $this->host($host);
    $this->port($port);
  }

  function secure($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'bool');
  }

  function host($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), function ($v) {
      strlen($v) or CodeException::fail("host: empty value");
      return $v;
    });
  }

  function port($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), function ($v) {
      is_numeric($v) or CodeException::fail("port($v): not a number");
      $v < 1 and CodeException::fail("port: zero value");
      return (int) $v;
    });
  }

  // Without leading '/' even if empty.
  function path($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), function ($v) {
      return ltrim($v, '/');
    });
  }

  // Without leading '?'.
  function query($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), function ($v) {
      return ltrim($v, '?');
    });
  }

  function scheme() {
    return $this->secure() ? 'wss' : 'ws';
  }

  // Quote from page 14:
  //
  //   The "resource-name" (also known as /resource name/ in Section 4.1) can be constructed by concatenating the following:
  //   o  "/" if the path component is empty
  //   o  the path component
  //   o  "?" if the query component is non-empty
  //   o  the query component
  function resourceName() {
    $path = '/'.$this->path();
    $query = $this->query();
    $query === '' or $query = "?$query";
    return $path.$query;
  }

  function uri() {
    return $this->scheme().'://'.$this->host.$this->portWithColon().$this->resourceName();
  }

  function portWithColon() {
    $port = $this->port();

    if ($port !== ($this->secure() ? 443 : 80)) {
      return ":$port";
    }
  }
}
