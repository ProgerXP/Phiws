<?php namespace Phiws\Plugins;

class UserAgent extends \Phiws\Plugin {
  function version() {
    return "Phiws/".\Phiws\BaseTunnel::PHIWS_VERSION;
  }

  function events() { 
    return ['clientBuildHeaders', 'serverBuildHeaders'];
  }

  function clientBuildHeaders(\Phiws\Client $cx, \Phiws\Headers\Bag $headers) {
    $headers->set("User-Agent", $this->version());
  }

  function serverBuildHeaders(\Phiws\ServerClient $cx, \Phiws\Headers\Bag $headers) { 
    $headers->set("X-Powered-By", $this->version());
    $headers->set("X-Phiws-Version", $cx::PHIWS_VERSION);
  }
}
