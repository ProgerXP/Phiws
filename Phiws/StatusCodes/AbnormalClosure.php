<?php namespace Phiws\StatusCodes;

// "It is designated for use in applications expecting a status code to indicate that the connection was closed abnormally, e.g., without sending or receiving a Close control frame."
class AbnormalClosure extends ReservedCode {
  const CODE = 1006;
  const TEXT = 'Abnormal Closure';
}
