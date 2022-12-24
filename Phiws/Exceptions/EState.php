<?php namespace Phiws\Exceptions;

// Thrown when trying to call an operation that needs different state. For example,
// when sending data using a non-connected (or already disconnected) tunnel.
class EState extends \Phiws\CodeException { }
