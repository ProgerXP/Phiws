<?php namespace Phiws\Frames;

abstract class PingOrPong extends \Phiws\ControlFrame {
  function __construct($payload = '') {
    parent::__construct();
    $len = strlen($payload);

    if ($len > static::MAX_LENGTH) {
      \Phiws\StatusCodes\MessageTooBig::fail("PingOrPong($len): payload is too long");
    } elseif ($len) {
      $this->applicationData = new \Phiws\DataSources\StringDS($payload);
    }
  }
}
