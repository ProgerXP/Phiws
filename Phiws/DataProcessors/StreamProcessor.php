<?php namespace Phiws\DataProcessors;

use Phiws\DataFrame;
use Phiws\Utils;
use Phiws\Exceptions\EStream;

abstract class StreamProcessor extends \Phiws\DataProcessor {
  protected $finCallback;

  protected $autoClose = false;
  protected $extHandle;
  protected $appHandle;

  function __destruct() {
    $this->autoClose and $this->close();
  }

  function finCallback($value = null) {
    return Utils::accessors($this, $this->{__FUNCTION__}, func_get_args());
  }

  function autoClose() {
    return $this->autoClose;
  }

  function extHandle() {
    return $this->extHandle;
  }

  function appHandle() {
    return $this->appHandle;
  }

  function close() {
    Utils::fcloseAndNull($this->exthandle);
    Utils::fcloseAndNull($this->apphandle);
  }

  protected function checkTotalLength() {
    $total = 
      ($this->exthandle ? EStream::ftell($this->extHandle) : 0) +
      ($this->apphandle ? EStream::ftell($this->appHandle) : 0);

    if ($total > static::$maxPayloadLength) {
      \Phiws\StatusCodes\MessageTooBig::fail("maximum message length (".static::$maxPayloadLength.") exceeded ($total)");
    }
  }
}
