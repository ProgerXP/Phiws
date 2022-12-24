<?php namespace Phiws\StatusCodes;

// "[..] received a message that is too big for it to process."
class MessageTooBig extends \Phiws\StatusCode {
  const CODE = 1009;
  const TEXT = 'Message Too Big'; 
}
