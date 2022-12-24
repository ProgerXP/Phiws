<?php namespace Phiws;

abstract class StatefulPlugin extends Plugin {
  function events() {
    return ['resetContext', 'clientConnect'];
  }

  function resetContext(BaseObject $cx) {
    $this->reset();
  }

  function clientConnect(Client $cx, ServerAddress $addr, $isReconnect) {
    $this->{$isReconnect ? 'clientReconnect' : 'clientFreshConnect'}();
  }

  protected function reset() { }
  protected function clientReconnect() { }
  protected function clientFreshConnect() { }
}
