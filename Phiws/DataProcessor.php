<?php namespace Phiws;

abstract class DataProcessor {
  // Frames (individual and entire message) wiTh longer payloads will be rejected.
  // 10 MiB by default.
  static $maxPayloadLength = 10485760;

  // First frame's FrameHeader.
  protected $header;

  // Null or BaseTunnel.
  protected $tunnel;

  protected $complete = false;

  // $frame should be already unmasked.
  function __construct(DataFrame $frame, BaseTunnel $tunnel = null) {
    $this->tunnel = $tunnel;
    $this->header = $frame->header();

    if ($frame instanceof Frames\Continuation) {
      CodeException::fail("DataProcessor: cannot initialize with a Continuation frame");
    }

    if ($frame->isComplete()) {
      if ($this->header->fin) {
        $this->processComplete($frame);
      } else {
        $this->initializeWith($frame);
      }
    } elseif (!$frame->isFirstPart()) {
      CodeException::fail("DataProcessor: must initialize with first frame in the series");
    } else {
      $this->initializeWith($frame);
    }
  }

  function payloadLength() {
    return $this->header->payloadLength;
  }

  function header() {
    return clone $this->header;
  }
    
  // Null or BaseTunnel.
  function tunnel() {
    return $this->tunnel;
  }

  function log($msg, $e = null, $level = 0) {
    $this->tunnel and $this->tunnel->log($msg, $e, $level);
  }

  protected function processComplete(DataFrame $frame) {
    $this->incoming($frame);
    $this->complete = true;
    $this->doProcessComplete($frame);
  }

  protected function initializeWith(DataFrame $frame) {
    $this->incoming($frame);
    $this->doInitializeWith($frame);
  }
    
  function append(DataFrame $frame) {
    $this->incoming($frame);
    $this->complete = $this->isFinal($frame);
    $this->doAppend($frame);
  }

  protected function incoming(DataFrame $frame) {
    // Protection against logic errors like an extension missing partialOffset flag
    // somewhere during processing (this has happened).
    if ($this->complete) {
      \Phiws\CodeException::fail("incoming: this data processor has already been finalized");
    }

    if ($frame->payloadLength() > static::$maxPayloadLength) {
      StatusCodes\MessageTooBig::fail("payload exceeds maximum length (".static::$maxPayloadLength."): ".$frame->describe());
    }
  }

  protected function isFinal(Frame $frame) {
    return $frame->header()->fin and ($frame->isComplete() or $frame->isLastPart());
  }

  // Called when received a single data frame, it was entirely read, was not
  // fragmented and so there will be no more sub-reads of this frame.
  abstract protected function doProcessComplete(DataFrame $frame);

  // Called when received a first data frame. It can be either fragmented 
  // (FIN unset) or partially read (FIN set), or both.
  abstract protected function doInitializeWith(DataFrame $frame);

  // Called when received a sub-read of the initial frame. $frame is Continuation
  // for a fragmented frame, or another Frame class for partial reading of the 
  // first frame.
  abstract protected function doAppend(DataFrame $frame);
}
