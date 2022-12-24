<?php namespace Phiws;

abstract class BaseObject {
  const ID_PREFIX = 'O';
  static $nextID = 1;

  protected $id;

  // Logger.
  protected $logger;

  // Plugins.
  protected $plugins;

  // Useful context options:
  // - socket: backlog (for Server)
  // - ssl: verify_peer, allow_self_signed, SNI_server_name, others
  protected $streamContextOptions = [];
  protected $streamContext;

  // Seconds. Can be fractional.
  protected $timeout = 5.0;

  // $loopWait - ms (1000 = 1 s).
  protected $loopWait = 50;

  protected $loopFail = true;

  function __construct() {
    $this->init();
    $this->reset();
  }

  /**
   * Accessors
   */

  function id() {
    return $this->id;
  }

  // Logger can be shared across multiple BaseObject's.
  function logger(Logger $value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args());
  }

  function plugins() {
    return $this->plugins;
  }

  function streamContextOptions(array $value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args());
  }

  function timeout($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'float');
  }

  function loopWait($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'int');
  }

  function loopFail($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'bool');
  }

  abstract function isStoppingLoop();

  /**
   * Common Methods
   */

  protected function init() {
    $this->id = sprintf('%s%03d', static::ID_PREFIX, static::$nextID++);
    $this->logger = new Loggers\InMemory;
    $this->plugins = new Plugins($this);
  }

  function reset() {
    $this->fire('resetContext');
    return $this;
  }

  function log($msg, object $e = null, $level = 0) {
    $this->logger->log($msg, $e, $level, $this->id);
  }

  function fire($event, array $args = []) {
    $postLogger = $this->logger->logEvent($event, $args, $this->id);

    try {
      $this->plugins->fire($event, $args);
      isset($postLogger) and $postLogger($args);
      return $this;
    } catch (\Throwable $e) {
      goto ex;
    } catch (\Exception $e) {
      ex:
      $this->log("$event: exception in a handler", $e, 'error');
      throw $e;
    }
  }

  protected function makeStreamContext() {
    if (!$this->streamContext) {
      $opt = $this->streamContextOptions;
      $this->fire(__FUNCTION__, [&$opt]);
      $this->streamContext = Exceptions\EStream::callType('resource', 'stream_context_create', $opt);
    }

    return $this->streamContext;
  }

  protected function prepareStream($handle, $secure) {
    stream_set_blocking($handle, true);
    stream_set_timeout($handle, 0, $this->timeout * 1000 * 1000);

    if ($secure) {
      $this->log("prepareStream: enabling crypto, method {$this->cryptoMethod()}");

      try {
        $e = null;
        // "Clients MUST use the Server Name Indication extension in the TLS handshake [...]"
        // This function sets SNI to the hostname given to stream_socket_client().
        $res = stream_socket_enable_crypto($handle, true, $this->cryptoMethod());
      } catch (\Throwable $e) {
        $res = null;
      } catch (\Exception $e) {
        $res = null;
      }

      if (!$res === true) {
        StatusCodes\TlsHandshakeFailed::fail("cannot enable crypto", null, $e);
      }
    }

    $this->fire(__FUNCTION__, [$handle, $secure]);
  }

  protected function prepareWrittenStream($handle) {
    // PHP comment (http://php.net/manual/en/function.stream-set-timeout.php#68543)
    // indicates that timeout might have no effect prior to fwrite();
    stream_set_timeout($handle, 0, $this->timeout * 1000 * 1000);
  }

  // Only required if this object is enabling crypto.
  protected function cryptoMethod() {
    CodeException::fail("cryptoMethod: not implemented");
  }

  // $times of 1 is different from calling loopTick() in that loop() catches
  // exceptions and measures time taken by processMessages(). $times allows
  // chaining several tunnels together in a coroutine fashion.
  function loop($times = null) {
    if ($this->isStoppingLoop()) {
      EState::fail("loop: state not ready");
    }

    while (!$this->isStoppingLoop() and ($times === null or $times-- > 0)) {
      if (isset($time)) {
        $rest = $this->loopWait - (microtime(true) - $time) * 1000;
        $rest > 0 and usleep(1000 * (int) $rest);
      }

      $time = microtime(true);
      try {
        $this->processMessages();

        $duration = (microtime(true) - $time) * 1000;
        $this->loopTick($this->loopWait - $duration, $duration);
      } catch (\Throwable $e) {
        goto ex;
      } catch (\Exception $e) {
        ex:
        $this->log('loop: exception', $e, 'error');
        if ($this->loopFail) { throw $e; }
      }
    }

    return $this;
  }

  function loopTick($maxWait, $iterDuration) {
    $this->fire('loopTick', [$maxWait, $iterDuration]);
  }

  abstract function processMessages();
}
