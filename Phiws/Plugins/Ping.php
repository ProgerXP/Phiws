<?php namespace Phiws\Plugins;

use Phiws\Utils;

class Ping extends \Phiws\Plugin {
  protected $interval = 30;
  protected $justPong = false;
  protected $disconnectTimeout;

  protected $lastPingTime;

  function interval($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'int');
  }

  function justPong($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'bool');
  }

  // If non-zero and justPong() is unset, connection is closed if another party 
  // hasn't sent a Pong within this interval.
  function disconnectTimeout($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'int');
  }

  function lastPingTime() {
    return $this->lastPingTime;
  }

  function events() {
    return ['clientConnected', 'serverClientConnected', 'loopTick'];
  }

  function clientConnected(\Phiws\Client $cx) {
    $this->setLastNow();
  }

  function serverClientConnected(\Phiws\ServerClient $client) {
    $this->setLastNow();
  }

  protected function setLastNow() {  
    $this->lastPingTime = microtime(true);
  }

  function loopTick(\Phiws\BaseObject $cx, $maxWait, $iterDuration) {
    if ($timeout = $this->disconnectTimeout 
        and $ping = $cx->writingState()->pingFrame and $ping->timeSent()) { 
      $pong = $cx->readingState()->pongFrame;
      $pongTime = $pong ? $pong->timeConstructed() : microtime(true);

      if ($pongTime < $ping->timeSent() - $timeout) {
        $cx->disconnect(new \Phiws\StatusCodes\GoingAway("pong not received within $timeout seconds"));
        return false;
      }
    }

    if ($this->lastPingTime + $this->interval <= microtime(true)) {
      $this->setLastNow();
      $cx->{"queue".($this->justPong ? 'UnsolicitedPong' : 'Ping')}();
    }
  }
}
