<?php namespace Phiws;

class DirectionState {
  public $tunnel;
  public $bytesOnWire = 0;
  public $closeFrame;
  public $pingFrame;
  public $pongFrame;

  // A "message" is a series of one DataFrame and zero or more Continuation frames.
  public $messageStart;   // null or DataFrame
  public $lastFragment;   // null, DataFrame (can === $messageStart)
  // stdClass for custom properties retained until message ends.
  public $messageCustom;  
  public $closeMessage = [];  // array of callable (DirectionState $this)

  // Only for $readingState.
  public $partialStart;   // null or Frame
  public $lastPartial;    // null, Frame (can === $partialStart)
  public $partialCustom;
  public $closePartial = [];

  function __construct(BaseTunnel $tunnel) {
    $this->tunnel = $tunnel;
    $this->close();
  }

  function __destruct() {
    $this->close();
  }

  function close() {
    $this->closeMessage();
    $this->closePartial();
  }

  function closeMessage(DataFrame $newStart = null) {
    if ($newStart instanceof Frames\Continuation) {
      CodeException::fail("closeMessage({$newStart->describe()}): new message cannot start with a Continuation");
    }

    foreach ($this->closeMessage as $func) { call_user_func($func, $this); }

    $this->messageStart = $newStart;
    $this->lastFragment = $newStart;
    $this->messageCustom = new \stdClass;
    $this->closeMessage = [];
  }

  function closePartial(Frame $newStart = null) {
    foreach ($this->closePartial as $func) { call_user_func($func, $this); }

    $this->partialStart = $newStart;
    $this->lastPartial = $newStart;
    $this->partialCustom = new \stdClass;
    $this->closePartial = [];
  }
}
