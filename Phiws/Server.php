<?php namespace Phiws;

use Phiws\Exceptions\EState;
use Phiws\Exceptions\EStream;

// $s = new Server(444);
// $s->start();
// $s->loop();
// ...   // from some callback: $s->stop();
class Server extends BaseObject implements \Countable, \IteratorAggregate {
  const ID_PREFIX = 'S';

  protected $extensions;
  protected $protocols;

  protected $ip;
  protected $port;

  protected $handle;
  protected $stopping;
  // Array of ServerClient.
  protected $clients;

  function __construct($port, $ip = '0.0.0.0') {
    parent::__construct();

    $this->extensions = new Extensions($this);
    $this->protocols = new Protocols($this);

    $this->port($port);
    $this->ip($ip);
  }

  function __destruct() {
    $this->stop();
  }

  /**
   * Accessors
   */

  function extensions() {
    return $this->extensions;
  }

  function protocols() {
    return $this->protocols;
  }

  function ip($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), function ($v) {
      if (!filter_var($v, FILTER_VALIDATE_IP)) {
        CodeException::fail("ip($v): invalid value");
      } else {
        return ip2long($v) ? $v : 0;
      }
    });
  }

  function port($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), function ($v) {
      if (!is_numeric($v) or $v < 1 or $v > 65535) {
        CodeException::fail("port($v): invalid value");
      } else {
        return (int) $v;
      }
    });
  }

  function isStarted() {
    return (bool) $this->handle;
  }

  function isStoppingLoop() {
    return $this->stopping;
  }

  #[\ReturnTypeWillChange]
  function count() {
    return count($this->clients);
  }

  #[\ReturnTypeWillChange]
  function getIterator() {
    return new \ArrayIterator($this->clients);
  }

  /**
   * Starting/Stopping
   */

  function gracefulStop(StatusCode $status = null) {
    $this->stopping = true;
    $this->disconnectAll(true, $status);
    return $this;
  }

  function disconnectAll($graceful, StatusCode $status = null) {
    $this->fire('serverDisconnectAll', [$graceful]);

    foreach ($this->clients as $client) {
      $client->{$graceful ? 'gracefulDisconnect' : 'disconnect'}($status);
    }

    return $this;
  }

  function stop() {
    if ($this->handle) {
      try {
        $this->disconnectAll(false);
      } catch (\Throwable $e) {
        goto ex;
      } catch (\Exception $e) {
        ex:
        $this->log('stop: silenced exception', $e);
      }

      Utils::fcloseAndNull($this->handle);
      $this->fire('serverStopped');
    }

    return $this;
  }

  function reset() {
    $this->stop();

    $this->stopping = false;
    $this->clients = [];

    return parent::reset();
  }

  function start() {
    if ($this->isStarted()) {
      EState::fail("start: server already started");
    }

    $this->reset();
    $addr = "$this->ip:$this->port";
    $this->log("serverStart: $addr");

    try {
      $this->fire('serverStart');

      try {
        $handle = $this->handle =
          stream_socket_server("tcp://$this->ip:$this->port", $errno, $error,
                              STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
                              $this->makeStreamContext());
      } catch (\Throwable $e) {
        goto ex1;
      } catch (\Exception $e) {
        ex1:
        Exceptions\EConnect::fail("start($addr): stream_socket_server() error: ".$e->getMessage());
      }

      if (!$handle) {
        Exceptions\EConnect::fail("start($addr): stream_socket_server() error $errno: $error");
      }

      $this->prepareStream($handle, false);
      $this->fire('serverStarted', [$handle]);
    } catch (\Throwable $e) {
      goto ex2;
    } catch (\Exception $e) {
      ex2:
      $this->log('serverStart: exception', $e, 'error');
      $this->stop();
      throw $e;
    }
  }

  /**
   * Looping, Processing Clients
   */

  function loopTick($maxWait, $iterDuration) {
    parent::loopTick($maxWait, $iterDuration);
    foreach ($this as $client) { $client->loopTick($maxWait, $iterDuration); }
  }

  // Accepts new waiting connections and reads new data from connected clients.
  //
  // PHP manual on stream_select():
  // "Using a timeout value of 0 allows you to instantaneously poll the status of the streams, however, it is NOT a good idea to use a 0 timeout value in a loop as it will cause your script to consume too much CPU time."
  // "It is much better to specify a timeout value of a few seconds, although if you need to be checking and running other code concurrently, using a timeout value of at least 200000 microseconds will help reduce the CPU usage of your script."
  function processMessages() {
    if (!$this->isStarted()) {
      EState::fail("processMessages: server not started");
    }

    $sec = (int) ($this->loopWait / 1000);
    $usec = (($this->loopWait / 1000) - $sec) * 1000;
    $handles = $this->handles(true);
    $null = null;

    $res = stream_select($handles, $null, $null, $sec, $usec);

    if ($res === false) {
      EStream::fail("processMessages: stream_select() error");
    }

    $acceptNew = false;

    foreach ($handles as $handle) {
      if ($handle === $this->handle) {
        $acceptNew = true;
      } else {
        foreach ($this->clients as $client) {
          if ($client->inHandle() === $handle) {
            $this->log("processMessages: change on {$client->id()}'s handle");
            $client->processMessages();
            $handle = null;
            break;
          }
        }

        if ($handle) {
          EStream::fail('processMessages: stream_select() returned unknown handle');
        }
      }
    }

    try {
      // Accepting after processing clients so we don't have to catch exception in the
      // method, store it and rethrow after processing the clients.
      $acceptNew and $this->acceptNewClients();
    } catch (\Throwable $e) {
      goto ex;
    } catch (\Exception $e) {
      ex:
      $this->log('acceptNewClients: exception during handshake', $e, 'warn');
    }

    return $res;
  }

  function handles($withMaster = false) {
    $res = [];
    $withMaster and $res[] = $this->handle;

    foreach ($this->clients as $client) {
      $res[] = $client->inHandle();
    }

    return $res;
  }

  function acceptNewClients() {
    if (!$this->isStarted()) {
      EState::fail("acceptNewClients: server not started");
    }

    $handle = stream_socket_accept($this->handle, $this->timeout, $peerName);

    if (!$handle) {
      EStream::fail('acceptNewClients: stream_socket_accept() error');
    }

    try {
      list($host, $port) = explode(':', $peerName, 2);
      $this->fire('serverClientAccepting', [$host, $port]);
      $this->log("acceptNewClients: accepted $host:$port");
      $client = ServerClient::forStream($handle, $host, $port, $this);
    } catch (\Throwable $e) {
      goto ex;
    } catch (\Exception $e) {
      ex:
      Utils::fcloseAndNull($handle);
      throw $e;
    }

    $this->linkClient($client);
    $this->fire('serverClientAccepted', [$client]);

    $client->handshake();
    return $this->clients[] = $client;
  }

  protected function linkClient(ServerClient $client) {
    $client->logger($this->logger);
    $client->plugins()->addFrom($this->plugins, $this);
    $client->extensions()->addFrom($this->extensions);
    $client->protocols()->addFrom($this->protocols, $this);
  }

  // Only for calling from within a ServerClient.
  function clientDisconnected(ServerClient $client) {
    $key = array_search($client, $this->clients, true);

    if ($key === false) {
      CodeException::fail('clientDisconnected: object not in the list');
    }

    unset($this->clients[$key]);
    $this->fire('serverClientDisconnected', [$client]);
  }
}
