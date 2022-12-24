<?php namespace Phiws\Exceptions;

// Thrown when client fails to connect or server fails to start. However, if connection
// failed due to other reasons (handshake error, etc.) another class will be used, 
// often EStream.
class EConnect extends \Phiws\CodeException { }
