<?php namespace Phiws;

// Section 5.5.
abstract class ControlFrame extends Frame {
  const MAX_LENGTH = 125;

  protected function init() {
    parent::init();

    // "Control frames are identified by opcodes where the most significant bit of the opcode is 1."
    if (static::OPCODE < 8) {
      CodeException::fail("ControlFrame: invalid opcode");
    }
  }

  protected function updateHeader() {
    // "All control frames MUST have a payload length of 125 bytes or less and MUST NOT be fragmented."
    if (($len = $this->header->payloadLength) > static::MAX_LENGTH) {
      \Phiws\StatusCodes\MessageTooBig::fail("ControlFrame($len): payload is too long");
    }

    parent::updateHeader();
  }
}
