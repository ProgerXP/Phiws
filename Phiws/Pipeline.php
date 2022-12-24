<?php namespace Phiws;

class Pipeline {
  public $extensions;
  public $frames = [];
  public $method;
  public $forward = true;
  public $terminator;
  public $ids = [];
  public $isClient;
  public $logger = null;

  function shiftID() {
    return $this->forward ? array_shift($this->ids) : array_pop($this->ids);
  }

  function log($msg, $e = null, $level = 0) {
    $this->logger and $this->logger->log($msg, $e, $level);
  }

  function logFrames($funcName) {
    if ($this->logger) {
      foreach ($this->frames as $i => $frame) {
        $ch = $i ? '+' : ':';
        $this->logger->log("$funcName$ch {$frame->describe()}");
      }
    }
  }
}
