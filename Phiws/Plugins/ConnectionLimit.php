<?php namespace Phiws\Plugins;

use Phiws\Utils;

class ConnectionLimit extends \Phiws\Plugin {
  protected $maxGlobal;
  protected $maxPerOrigin = 100;
  protected $whitelistOrigins = [];

  protected $currentPerOrigin;

  function maxGlobal($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'int');
  }

  function maxPerOrigin($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'int');
  }

  // Array of IP prefixes (a prefix doesn't have to end on '.'): ['127.', '192.168.'].
  // Prefixes should be normalized, '1.02.' won't work.
  function whitelistOrigins(array $value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args());
  }

  function isWhitelisted($host) {
    foreach ((array) $this->whitelistOrigins as $prefix) {
      if (!strncmp($host, $prefix, strlen($prefix))) {
        return $prefix;
      }
    }
  }

  function events() {
    return ['serverStarted', 'serverClientAccepting', 'serverClientAccepted',
            'serverClientDisconnected'];
  }

  function serverStarted(\Phiws\Server $cx, $handle) {
    $this->currentPerOrigin = [];
  }

  function serverClientAccepting(\Phiws\Server $cx, $host, $port) {
    $log = null;

    if (count($cx) >= $this->maxGlobal) {
      $log = "maximum number of global clients ($this->maxGlobal) reached";
    } elseif (isset($this->currentPerOrigin[$host]) and 
              count($this->currentPerOrigin[$host]) >= $this->maxPerOrigin) {
      $log = "maximum number of clients per origin ($this->maxPerOrigin) reached";
    }

    if (!$log) {
      return;
    } elseif ($this->isWhitelisted($host)) {
      $cx->log("serverClientAccepting($host): accepting whitelisted origin");
    } else {
      $cx->log("serverClientAccepting($host): $log", null, 'warn');
      \Phiws\StatusCodes\TryAgainLater::fail();
    }
  }

  function serverClientAccepted(\Phiws\Server $cx, \Phiws\ServerClient $client) {
    $ref = &$this->currentPerOrigin[$client->clientHost()];
    $ref or $ref = [];
    $ref[$client->clientPort()] = true;
  }

  function serverClientDisconnected(\Phiws\Server $cx, \Phiws\ServerClient $client) {
    unset( $this->currentPerOrigin[$client->clientHost()][$client->clientPort()] );
  }
}
