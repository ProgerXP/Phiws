<?php namespace Phiws\DataProcessors;

use Phiws\DataFrame;

class Blackhole extends \Phiws\DataProcessor {
  protected function doProcessComplete(DataFrame $frame) {
    $this->logInput($frame);
  }

  protected function doInitializeWith(DataFrame $frame) {
    $this->logInput($frame);
  }

  protected function doAppend(DataFrame $frame) {
    $this->logInput($frame);
  }

  function logInput(DataFrame $frame) {
    $data = $frame->applicationData() ?: $frame->extensionData();

    if ($this->tunnel and $data) {
      if ($frame instanceof \Phiws\Frames\Continuation) {
        $msg = '(continuation frame discarded)';
      } elseif ($frame->isComplete() or $frame->isFirstPart()) {
        $msg = 'data frame discarded';
      } else {
        $msg = '(partial sub-read discarded)';
      }

      $info = $frame->describe();

      if ($frame->extensionData()) {
        $info .= " +extData";
      }

      if (!$frame->applicationData()) {
        $info .= ' -appData';
      }

      if ($frame instanceof \Phiws\Frames\TextData) {
        $info .= ': '.$data->readHead(40);
      } else {
        $info .= ': '.\Phiws\Utils::dump($data->readHead(12));
      }

      $this->tunnel->log("$msg: $info");
    }
  }
}
