<?php namespace Phiws\Plugins;

class RequestURI extends \Phiws\StatefulPlugin {
  protected $hostName;
  protected $baseURI;
  protected $redirectCode = 301;
  protected $redirectURI;
  protected $maxRedirects = 20;
  
  protected $redirectCounter = 0;

  // Any can be '' to skip checking. $baseURI is a prefix, i.e. if it doesn't end on
  // "/" - the check will pass on "{$baseURI}foobar" and "$baseURI/foo/bar".
  function __construct($hostName, $baseURI) {
    $this->hostName = $hostName;
    $this->baseURI = ltrim($baseURI, '/');
  }

  /**
   * Accessors
   */

  function hostName() {
    return $this->hostName;
  }

  function baseURI() {
    return $this->baseURI;
  }

  function redirectCode() {
    return $this->redirectCode;
  }

  function redirectURI() {
    return $this->redirectURI;
  }

  function redirectTo($uri = null, $code = 301) {
    if ($code < 300 or $code >= 400) {
      \Phiws\CodeException::fail("redirectTo($code): HTTP status code out of range");
    }

    $this->redirectURI = $value;
    $this->redirectCode = (int) $code;
  }

  function maxRedirects($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'int');
  }

  /**
   * Plugin
   */

  function events() { 
    return array_merge(parent::events(), [
      'clientHandshakeStatus', 'serverCheckHeaders', 'serverSendHandshakeError',
    ]);
  }

  protected function clientFreshConnect() {
    $this->redirectCounter = 0;
  }

  function clientHandshakeStatus(\Phiws\Client $cx, \Phiws\Headers\Status $status, \Phiws\ServerAddress &$reconnect = null) {
    // 301 Moved Permanently
    // 302 Found
    // 303 See Other
    // 307 Temporary Redirect
    // 308 Permanent Redirect

    if (in_array($status->code(), [301, 302, 303, 307, 308])) {
      $location = $cx->serverHeaders->get('Location');

      if (!$location) {
        $cx->log("clientHandshakeStatus: no Location for redirect status {$status->code()}");
      } elseif ($this->redirectCounter >= $this->maxRedirects) {
        $cx->log("clientHandshakeStatus: max number of redirects reached ($this->maxRedirects)");
      } else {
        $this->redirectCounter++;
        $reconnect = $cx->address();
        $reconnect->path($location);
        return false;
      }
    }
  }

  function serverCheckHeaders(\Phiws\ServerClient $cx, \Phiws\Headers\Bag $headers) {
    $host = $headers->get('Host');

    if (strcasecmp($host, $this->hostName)) {
      \Phiws\StatusCodes\RequestUriMismatch::fail("Host($host): unacceptable");
    }

    $uri = ltrim($headers->uri(), '/');

    if (strncmp($uri, $this->baseURI, strlen($this->baseURI))) {
      \Phiws\StatusCodes\RequestUriMismatch::fail("Request-URI($uri): unacceptable");
    }
  }

  // Section 4.2.2, point 3.
  function serverSendHandshakeError(\Phiws\ServerClient $cx, \Phiws\StatusCode $code, \Phiws\Headers\Bag $headers, array &$output) {
    if (isset($this->redirectURI)) {
      $cx->log("redirecting to $this->redirectURI with $this->redirectCounter");
      $code->httpCode($this->redirectCode);
      $headers->set('Location', $this->redirectURI);
    }
  }
}
