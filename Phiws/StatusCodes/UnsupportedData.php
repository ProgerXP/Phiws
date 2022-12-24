<?php namespace Phiws\StatusCodes;

// "[...] received a type of data it cannot accept (e.g., an endpoint that understands only text data MAY send this if it receives a binary message)."
class UnsupportedData extends \Phiws\StatusCode {
  const CODE = 1003;
  const TEXT = 'Unsupported Data';
}
