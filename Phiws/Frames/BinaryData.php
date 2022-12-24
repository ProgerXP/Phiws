<?php namespace Phiws\Frames;

// The "Payload data" is arbitrary binary data whose interpretation is solely up to the application layer.
class BinaryData extends \Phiws\DataFrame {
  const OPCODE = 0x02;
}
