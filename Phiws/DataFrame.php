<?php namespace Phiws;

abstract class DataFrame extends Frame {
  protected function init() {
    parent::init();

    // "[...] identified by opcodes where the most significant bit of the opcode is 0."
    if (static::OPCODE >= 8) {
      CodeException::fail("DataFrame: invalid opcode");
    }
  }

  // Can return null (no data set).
  function applicationData(DataSource $value = null) {
    return Utils::accessor($this, $this->applicationData, func_get_args());
  }
}
