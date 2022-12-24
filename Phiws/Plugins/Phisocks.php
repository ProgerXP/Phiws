<?php namespace Phiws\Plugins;

class Phisocks extends \Phiws\Plugin {
  protected $phisocks;

  function __construct(\Phisocks $phisocks) {
    $this->phisocks = $phisocks;
  }

  function phisocks() {
    return $this->phisocks;
  }

  function events() {
    return ['clientOpenSocket', 'disconnected'];
  }

  function clientOpenSocket(\Phiws\Client $client, &$inHandle, &$outHandle) { 
    $phisocks = $this->phisocks;
    $addr = $client->address();

    if ($addr->secure()) {
      $ref = &$phisocks->contextOptions['ssl']['SNI_server_name'];
      // If unset SNI will equal to the proxy's hostname.
      $ref or $ref = $addr->host();
    }

    $phisocks->connect($addr->host(), $addr->port());
    $addr->secure() and $phisocks->enableCrypto();
    $inHandle = $outHandle = $phisocks->handle();

    return false;
  }

  function disconnected(\Phiws\BaseTunnel $cx, \Phiws\StatusCode $code = null) {
    $this->phisocks->close();
  }
}
