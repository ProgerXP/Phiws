<?php namespace Phiws\StatusCodes;

// "[...] an endpoint is "going away", such as a server going down or a browser having navigated away from a page."
class GoingAway extends \Phiws\StatusCode {
  const CODE = 1001;
  const TEXT = 'Going Away';

  protected $httpCode = 503;
}
