<?php namespace Phiws\Plugins;

class HttpBasicAuth extends \Phiws\Plugin {
  const HEADER = 'WWW-Authenticate';

  protected $login;
  protected $password;

  function __construct($login, $password) {
    $this->login = $login;
    $this->password = $password;
  }

  function login($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args());
  }

  function password($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args());
  }

  function getEncoded() {
    return base64_encode("$this->login:$this->password");
  }

  function events() { 
    return ['clientBuildHeaders', 'clientCheckHeaders'];
  }

  function clientBuildHeaders(\Phiws\Client $cx, \Phiws\Headers\Bag $headers) {
    $headers->add(static::HEADER, $this->getEncoded());
  }

  // Section 4.2.2, point 2.
  function clientCheckHeaders(\Phiws\Client $cx, \Phiws\Headers\Bag $headers) { 
    $value = $headers->get(static::HEADER);

    if ($value !== $this->getEncoded()) {
      \Phiws\StatusCodes\MalformedHttpHeader::fail(static::HEADER." ($value): unacceptable");
    }
  }
}
