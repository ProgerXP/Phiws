<?php namespace Phiws\StatusCodes;

// "It is designated for use in applications expecting a status code to indicate that no status code was actually present."
class NoStatusReceived extends ReservedCode {
  const CODE = 1005;
  const TEXT = 'No Status Received';
}
