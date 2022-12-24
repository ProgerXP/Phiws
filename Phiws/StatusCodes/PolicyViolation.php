<?php namespace Phiws\StatusCodes;

// "This is a generic status code that can be returned when there is no other more suitable status code (e.g., 1003 or 1009) or if there is a need to hide specific details about the policy."
class PolicyViolation extends \Phiws\StatusCode {
  const CODE = 1008;
  const TEXT = 'Policy Violation';

  protected $httpCode = 403;
}
