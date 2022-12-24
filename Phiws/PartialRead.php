<?php namespace Phiws;

class PartialRead {
  public $header;
  public $firstFrame;
  public $nextOffset = 0;

  function isComplete() {
    return $this->nextOffset >= $this->header->payloadLength;
  }
}
