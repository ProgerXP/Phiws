<?php namespace Phiws\Frames;

use Phiws\StatusCodes\PrivateCode;

// Section 5.5.1.
class Close extends \Phiws\ControlFrame {
  const OPCODE = 0x08;

  protected $statusCode;

  // "The Close frame MAY contain a body (the "Application data" portion of the frame) that indicates a reason for closing, such as an endpoint shutting down, an endpoint having received a frame too large, or an endpoint having received a frame that does not conform to the format expected by the endpoint."
  function statusCode(\Phiws\StatusCode $code = null) {
    return \Phiws\Utils::accessor($this, $this->{__FUNCTION__}, func_get_args());
  }

  protected function updateHeader() {
    // "If there is a body, the first two bytes of the body MUST be a 2-byte unsigned integer (in network byte order) [...]. Following [...], the body MAY contain UTF-8-encoded data with value /reason/, the interpretation of which is not defined by this specification."
    if ($code = $this->statusCode) {
      $data = $code ? pack('n', $code->code()).$code->text() : '';
      $this->applicationData = new \Phiws\DataSources\StringDS($data);
    } else {
      $this->applicationData = null;
    }

    parent::updateHeader();
  }

  function updateFromData() {
    parent::updateFromData();

    if ($data = $this->applicationData) {
      $data = $data->readAll();
      list(, $code) = unpack('n', $data);
      $class = \Phiws\StatusCode::codeClass($code);

      if ($class) {
        $this->statusCode = new $class(substr($data, 2));
      } elseif ($code >= PrivateCode::START and $code <= PrivateCode::END) {
        $this->statusCode = new PrivateCode(substr($data, 2), $code);
      } 
    }
  }

  function describe() {
    $res = parent::describe();
    $this->statusCode and $res .= ' '.$this->statusCode->describe();
    return $res;
  }
}
