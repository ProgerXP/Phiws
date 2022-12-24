<?php namespace Phiws\Plugins;

use Phiws\Utils;

// 4.2.2, point 4:
// "If the origin indicated is unacceptable to the server, then it SHOULD respond to the WebSocket handshake with a reply containing HTTP 403 Forbidden status code."
class Origin extends \Phiws\Plugin {
  protected $origins = [];
  protected $failIfNone = true;

  function origins(array $value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'array');
  }

  // $host = '(doma.in|i.p)[:port]'. IPv6 addresses are allowed. If port is omitted,
  // any is accepted. Origins can duplicate (ports will be merged). Domains won't 
  // be resolved, i.e. for addOrigin(domain), if domain maps to an IP and there's a 
  // request from that IP (http://1.2.3.4, not http://domain) - it will be denied.
  function addOrigin($host) {
    list($host, $port) = $this->parseOrigin($host);
    $ref = &$this->origins[$host];
    $ref = $port ? array_merge($ref, [$port]) : true;
    return $this;
  }

  function allowedPortsFor($host) {
    return isset($this->origins[$host]) ? $this->origins[$host] : null;
  }

  function failIfNone($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'bool');
  }

  function refreshInterval($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'int');
  }

  protected function parseOrigin($origin) {
    $port = (string) substr(strrchr($origin, ':'), 1);

    if ($port !== '' and ltrim($port, '0..9') === '') {
      $host = substr($origin, 0, -strlen($port) - 1);
    } else {
      $port = null;
      $host = $origin;
    }

    return [$host, $port];
  }

  function events() {
    return ['serverCheckHeaders'];
  }

  function serverCheckHeaders(\Phiws\ServerClient $cx, \Phiws\Headers\Bag $headers) {
    $origin = $headers->get('Origin');

    if (!$origin) { 
      if ($this->failIfNone) {
        \Phiws\StatusCodes\PolicyViolation::fail("Origin is required");
      } else {
        // Success, origin is not checked further.
        return false;
      }
    }

    if ($url = parse_url($origin)) { 
      $ports = $this->allowedPortsFor($url['host']);
      $port = isset($url['port']) ? $url['port'] : null;

      if (!isset($port)) {
        switch ($url['scheme']) {
        case 'http':    $port = 80; break;
        case 'https':   $port = 443; break;
        }
      }

      if ($ports === true or in_array($ports, $port, true)) {
        // Origin allowed.
        return false;
      }
    }

    \Phiws\StatusCodes\PolicyViolation::fail("Origin denied ($origin)");
  }
}
