<?php namespace Phiws\DataProcessors;

use Phiws\DataFrame;
use Phiws\Utils;

class BufferAndTrigger extends StreamProcessor {
  protected function fireTrigger(\Phiws\DataSource $applicationData = null, \Phiws\DataSource $extensionData = null) {
    if ($extensionData or $applicationData) {
      if ($this->tunnel) {
        $this->tunnel->fire('bufferedFrameComplete', [$applicationData, $extensionData]);
      }

      if ($this->finCallback) {
        call_user_func($this->finCallback, $applicationData, $extensionData);
      }
    }
  }

  protected function doProcessComplete(DataFrame $frame) {
    $this->fireTrigger($frame->applicationData(), $frame->extensionData());
  }

  protected function doInitializeWith(DataFrame $frame) {
    $this->close();
    $this->append($frame);
  }

  protected function doAppend(DataFrame $frame) {
    $this->log("doAppend: {$frame->describe()}");

    if ($frame->extensionData()) {
      $this->extHandle or $this->extHandle = Utils::newTempStream();
      $frame->extensionData()->copyTo($this->extHandle);
    }

    if ($frame->applicationData()) {
      $this->appHandle or $this->appHandle = Utils::newTempStream();
      $frame->applicationData()->copyTo($this->appHandle);
    }

    $this->checkTotalLength();

    if ($this->isFinal($frame)) {
      $extData = $appData = null;

      if ($this->extHandle) {
        $extData = new \Phiws\DataSources\Stream($this->extHandle, true);
        $this->extHandle = null;
      }

      if ($this->appHandle) {
        $appData = new \Phiws\DataSources\Stream($this->appHandle, true);
        $this->appHandle = null;
      }

      $this->fireTrigger($appData, $extData);
    }
  }
}
