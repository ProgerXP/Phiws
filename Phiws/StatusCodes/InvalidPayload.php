<?php namespace Phiws\StatusCodes;

// "[...] received data within a message that was not consistent with the type of the message (e.g., non-UTF-8 [RFC3629] data within a text message)."
class InvalidPayload extends \Phiws\StatusCode {
  const CODE = 1007;
  const TEXT = 'Invalid Frame Payload Data';
}
