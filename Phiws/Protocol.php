<?php namespace Phiws;

abstract class Protocol {
  const ID = '';

  function id() {
    return static::ID;
  }

  function cloneFor($newContext) {
    return clone $this;
  }
}
