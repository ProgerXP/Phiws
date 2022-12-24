<?php namespace Phiws\Plugins;

use Phiws\Client;

class AutoReconnect extends \Phiws\StatefulPlugin {
  // Seconds, can be fractional.
  protected $maxWait = 120;

  protected $currentWait;

  protected $statuses = [
    // Too Many Requests.
    429 => [10, 2],
    // Service Unavailable.
    503 => [3, 2.5],
    // Gateway Timeout.
    504 => [3, 2.5],
  ];

  /**
   * Accessors
   */

  function maxWait($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'int');
  }

  function currentWait() {
    return Utils::cloneReader($this->currentWait);
  }

  // Won't wait on $httpCode. The connection will fail unless other plugins process it.
  function ignoreStatus($httpCode) {
    $this->statuses[$httpCode] = null;
    return $this;
  }

  function waitOnStatus($httpCode, $intialDelay, $multiplier) {
    $this->statuses[$httpCode] = [(int) $intialDelay, (float) $multiplier];
    return $this;
  }

  /**
   * Plugin
   */

  function events() {
    return array_merge(parent::events(), ['clientHandshakeStatus']);
  }

  protected function clientFreshConnect() { 
    $this->currentWait = null;
  }

  function clientHandshakeStatus(Client $cx, \Phiws\Headers\Status $status, \Phiws\ServerAddress &$reconnect = null) {
    $wait = &$this->statuses[$status->code()];
    if (!$wait) { return; }

    list($delay, $mul) = $wait;

    if (!$this->currentWait) {
      $wait = new ARCurrentWait;
      $wait->since = microtime(true);
      $wait->lastDelay = $delay;
    } else {
      $delay = $this->currentWait->delay *= $mul;

      if ($delay + microtime(true) - $this->currentWait->since >= $this->maxWait) {
        $this->log('clientHandshakeStatus: maxWait reached');
        $delay = null;
      }
    }

    if (isset($delay)) {
      $delay = mt_rand(1.1 * $delay * 1000);
      $this->log("clientHandshakeStatus: got {$status->code()} ({$status->text()}), sleeping for $delay ms");
      usleep($delay * 1000);
      $reconnect = $cx->address();
      return false;
    }
  }
}

class ARCurrentWait {
  public $since;
  public $lastDelay;
}
