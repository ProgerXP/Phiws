<?php namespace Phiws;

class LogEntry {
  public $time;
  public $message;
  public $exception;
  public $level;
  public $sourceID;

  function __construct(array $fields = []) {
    $this->time = time();

    foreach ($fields as $name => $value) {
      $this->$name = $value;
    }
  }
}
