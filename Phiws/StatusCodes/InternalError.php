<?php namespace Phiws\StatusCodes;

// "a server is terminating the connection because it encountered an unexpected condition that prevented it from fulfilling the request."
// RFC Errata 3227:
// "1011 indicates that a remote endpoint is terminating the connection because it encountered an unexpected condition that prevented it from fulfilling the request."
class InternalError extends \Phiws\StatusCode {
  const CODE = 1011;
  const TEXT = 'Internal Error';
}
