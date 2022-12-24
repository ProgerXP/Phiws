<?php namespace Phiws\StatusCodes;

// http://www.ietf.org/mail-archive/web/hybi/current/threads.html#09670
// "1013 indicates that the service is experiencing overload. a client should only connect to a different IP (when there are multiple for the target) or reconnect to the same IP upon user action."
class TryAgainLater extends \Phiws\StatusCode {
  const CODE = 1013;
  const TEXT = 'Try Again Later';

  protected $httpCode = 503;
}
