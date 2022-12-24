<?php namespace Phiws;

abstract class Masker {
  abstract function updateHeader(FrameHeader $header);
  abstract function mask(&$payload);
  abstract function unmask(&$payload);
}
