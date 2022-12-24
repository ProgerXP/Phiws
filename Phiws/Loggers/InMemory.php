<?php namespace Phiws\Loggers;

class InMemory extends \Phiws\Logger implements \Countable, \IteratorAggregate {
  static $dumpOnShutdown = false;

  protected $limit = 100;

  // Array of LogEntry.
  protected $messages = [];

  function __construct() {
    static::$dumpOnShutdown and register_shutdown_function([$this, 'dump']);
  }

  /**
   * Accessors
   */

  function limit($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'int');
  }

  function messages() {
    return $this->messages;
  }

  #[\ReturnTypeWillChange]
  function count() {
    return count($this->messages);
  }

  // Latest messages in the end.
  #[\ReturnTypeWillChange]
  function getIterator() {
    return new \ArrayIterator($this->messages);
  }

  function toArray() {
    return $this->messages;
  }

  /**
   * Logging
   */

  function clear() {
    $this->messages = [];
    return $this;
  }

  protected function doLog(\Phiws\LogEntry $entry) {
    $this->messages[] = $entry;
    return $this->truncate();
  }

  function truncate($limit = null) {
    isset($limit) or $limit = $this->limit;
    array_splice($this->messages, 0, -$limit);
    return $this;
  }

  function dump() {
    if (!static::$dumpOnShutdown or !count($this)) { return; }

    echo "<hr><pre>\n";

    foreach (array_reverse($this->messages) as $entry) {
      echo $this->format($entry);
      echo "\n";
    }

    echo "</pre>";
  }
}
