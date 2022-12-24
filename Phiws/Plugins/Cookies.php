<?php namespace Phiws\Plugins;

class Cookies extends \Phiws\Plugin implements \Countable, \IteratorAggregate {
  // For Client after handshake is set to cookies returned by the server.
  protected $cookies = [];

  // Returns array of 'name' => $options (with 'name' and 'value' keys).
  function cookies(array $value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'array');
  }

  // $name and $value will be URL-encoded.
  //
  // $options (all optional):
  // - expire (absolute timestamp) or age (relative to time()) - int; 
  //   session-only if missing
  // - path - /some/path/...
  // - domain - host.name:8778
  // - secure - bool
  // - httponly or httpOnly - bool
  function addCookie($name, $value, array $options = []) {
    $options = compact('name', 'value') + $options;

    if (isset($options['age'])) {
      $options['expire'] = time() + $options['age'];
    }

    if (isset($options['httponly'])) {
      $options['httpOnly'] = $options['httponly'];
    }

    $this->cookies[$name] = $options;
    return $this;
  }

  function encode(array $cookie) {
    extract($cookie, EXTR_SKIP);

    $parts = [];
    $parts[] = urlencode($name).'='.urlencode($value);

    if (isset($expire)) {
      $parts[] = 'expires='.date(DATE_COOKIE, $expire);
    }

    isset($path) and $parts[] = "path=$path";
    isset($domain) and $parts[] = "domain=$domain";
    empty($secure) or $paths[] = 'secure';
    empty($httponly) or $paths[] = 'httponly';

    if (strpbrk(join($parts), ';,')) {
      \Phiws\CodeException::fail("encode($name): cookie parameters are not properly encoded");
    }

    return join('; ', $parts);
  }

  #[\ReturnTypeWillChange]
  function count() {
    return count($this->cookies);
  }

  #[\ReturnTypeWillChange]
  function getIterator() {
    return new \ArrayIterator($this->cookies);
  }

  function events() {
    return ['clientBuildHeaders', 'clientCheckHeaders', 'serverBuildHeaders'];
  }

  // When client connects to a server he sends "stored" cookies (i.e. just name/value
  // pairs).
  function clientBuildHeaders(\Phiws\Client $cx, \Phiws\Headers\Bag $headers) {
    $list = [];

    foreach ($this->cookies as $cookie) {
      $list[] = $this->encode(array_intersect_key($cookie, ['name' => 1, 'value' => 1]));
    }

    $headers->add('Cookie', join('; ', $list));
  }

  function clientCheckHeaders(\Phiws\Client $cx, \Phiws\Headers\Bag $headers) {
    $this->cookies = [];

    foreach ($headers->getParametrizedTokens('Set-Cookie', true) as $header) {
      list($id, $params) = $header;

      if ($id !== '' or !$params) {
        $cx->log("clientCheckHeaders($id): skipping malformed cookie (no \"=value\")");
        continue;
      } 

      $cookie = [];

      list($name, $value) = array_shift($params);
      $cookie['name'] = urldecode($name);
      $cookie['value'] = urldecode($value);

      foreach ($params as $param) {
        list($param, $value) = $param;

        switch ($param) {
        case 'expires':
          $time = strtotime($value);
          $time and $cookie['expire'] = $time;
          break;
        case 'path':
        case 'domain':
          $cookie[$param] = $value;
          break;
        case 'secure':
        case 'httponly':
          $cookie[$param] = (bool) $value;
          break;
        default:
          \Phiws\CodeException::fail("clientCheckHeaders($name, $param): unknown cookie attribute");
        }
      }

      $this->cookies[] = $cookie;
    }
  }

  // When server sends response to a client (usually browser) along with the cookies
  // to be stored.
  function serverBuildHeaders(\Phiws\ServerClient $cx, \Phiws\Headers\Bag $headers) {
    foreach ($this->cookies as $cookie) {
      $headers->add('Set-Cookie', $this->encode($cookie));
    }
  }
}
