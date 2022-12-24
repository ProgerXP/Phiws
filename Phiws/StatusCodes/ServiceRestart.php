<?php namespace Phiws\StatusCodes;

// http://www.ietf.org/mail-archive/web/hybi/current/threads.html#09670
// "1012 indicates that the service is restarted. a client may reconnect, and if it choses to do, should reconnect using a randomized delay of 5 - 30s."
class ServiceRestart extends \Phiws\StatusCode {
  const CODE = 1012;
  const TEXT = 'Service Restart';

  protected $httpCode = 503;
}
