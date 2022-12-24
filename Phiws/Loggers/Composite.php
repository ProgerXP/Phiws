<?php namespace Phiws\Loggers;

use Phiws\Logger;

class Composite extends \Phiws\Logger implements \Countable, \IteratorAggregate {
  protected $loggers = [];

  function addLogger(Logger $logger) {
    $this->hasLogger($logger) or $this->loggers[] = $logger;
    return $this;
  }

  function hasLogger(Logger $logger) {
    return in_array($logger, $this->loggers, true);
  }

  function removeLogger(Logger $logger) {
    $index = array_search($logger, $this->loggers, true);

    if ($index !== false) {
      unset($this->loggers[$index]);
    }

    return $this;
  }

  function removeAllLoggers() {
    $this->loggers = [];
    return $this;
  }

  function loggers() {
    return $this->loggers;
  }

  #[\ReturnTypeWillChange]
  function count() {
    return count($this->loggers);
  }

  // Latest messages in the end.
  #[\ReturnTypeWillChange]
  function getIterator() {
    return new \ArrayIterator($this->loggers);
  }

  protected function doLog(\Phiws\LogEntry $entry) {
    $ex = null;

    foreach ($this->loggers as $logger) {
      try {
        $logger->log($entry);
      } catch (\Throwable $e) {
        goto ex;
      } catch (\Exception $e) {
        ex:
        $this->log('Loggers\\Composite: exception in '.get_class($logger), $e, 'error');
        $ex = $e;
      }
    }

    if (isset($ex)) { throw $ex; }
  }
}
