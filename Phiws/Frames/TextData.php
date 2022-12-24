<?php namespace Phiws\Frames;

// "The "Payload data" is text data encoded as UTF-8. Note that a particular text frame might include a partial UTF-8 sequence; however, the whole message MUST contain valid UTF-8. Invalid UTF-8 in reassembled messages is handled as described in Section 8.1."
// "[...] finds that the byte stream is not, in fact, a valid UTF-8 stream, that endpoint MUST _Fail the WebSocket Connection_."
// In other words immediately disconnect() by sending a Close frame but without waiting for one back.
class TextData extends \Phiws\DataFrame {
  const OPCODE = 0x01;
}
