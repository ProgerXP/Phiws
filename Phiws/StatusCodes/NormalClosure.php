<?php namespace Phiws\StatusCodes;

// "[...] the purpose for which the connection was established has been fulfilled."
class NormalClosure extends \Phiws\StatusCode {
  const CODE = 1000;
  const TEXT = 'Normal Closure';
}
