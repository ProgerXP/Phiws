<?php namespace Phiws;

// Thrown on logic errors, wrong parameters and such.
//
// Text is normally set to "methodName: message..." where methodName indicates where
// it was thrown (class name is not included). For __construct errors, methodName
// equals class name without namespace.
class CodeException extends StatusCodes\InternalError { 
  const TEXT = 'Code Exception';
}
