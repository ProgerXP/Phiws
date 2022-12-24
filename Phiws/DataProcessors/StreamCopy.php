<?php namespace Phiws\DataProcessors;

use Phiws\DataFrame;

class StreamCopy extends StreamProcessor {
  // Null handle means this data won't be copied.
  function __construct($autoClose, $appHandle = null, $extHandle = null) {
    $this->autoClose = $autoClose;
    $this->appHandle = $appHandle;
    $this->extHandle = $extHandle;
  }

  protected function doProcessComplete(DataFrame $frame) {
    $this->copy($frame);
  }

  protected function doInitializeWith(DataFrame $frame) {
    $this->copy($frame);
  }

  protected function doAppend(DataFrame $frame) {
    $this->copy($frame);
  }

  function copy(DataFrame $frame) {
    if ($this->extHandle and $data = $frame->extensionData()) {
      $data->copyTo($this->extHandle);
    }

    if ($this->appHandle and $data = $frame->applicationData()) {
      $data->copyTo($this->appHandle);
    }

    $this->checkTotalLength();

    if ($this->isFinal($frame) and $this->trigger) {
      call_user_func($this->trigger, $this);
    }
  }
}
