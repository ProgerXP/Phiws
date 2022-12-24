<?php namespace Phiws;

/*
  7.4.2. Reserved Status Code Ranges

  0-999

    Status codes in the range 0-999 are not used.

  1000-2999

    Status codes in the range 1000-2999 are reserved for definition by this protocol,
    its future revisions, and extensions specified in a permanent and readily
    available public specification.

  3000-3999

    Status codes in the range 3000-3999 are reserved for use by libraries, frameworks,
    and applications. These status codes are registered directly with IANA. The
    interpretation of these codes is undefined by this protocol.
  
  4000-4999
  
    Status codes in the range 4000-4999 are reserved for private use and thus can't be
    registered. Such codes can be used by prior agreements between WebSocket
    applications. The interpretation of these codes is undefined by this protocol.
*/

// Page 65 - registry of status texts and codes.
abstract class StatusCode extends \Exception {
  // WebSocket status code.
  const CODE = 0;
  // WebSocket status text such as "Abnormal Closure".
  const TEXT = '';

  static protected $classes = [
    1000    => 'Phiws\\StatusCodes\\NormalClosure',
    1001    => 'Phiws\\StatusCodes\\GoingAway',
    1002    => 'Phiws\\StatusCodes\\ProtocolError',
    1003    => 'Phiws\\StatusCodes\\UnsupportedData',
    1004    => 'Phiws\\StatusCodes\\Reserved1004',
    1005    => 'Phiws\\StatusCodes\\NoStatusReceived',
    1006    => 'Phiws\\StatusCodes\\AbnormalClosure',
    1007    => 'Phiws\\StatusCodes\\InvalidPayload',
    1008    => 'Phiws\\StatusCodes\\PolicyViolation',
    1009    => 'Phiws\\StatusCodes\\MessageTooBig',
    1010    => 'Phiws\\StatusCodes\\ClientExtensionsNotNegotiated',
    1011    => 'Phiws\\StatusCodes\\InternalError',
    1012    => 'Phiws\\StatusCodes\\ServiceRestart',
    1013    => 'Phiws\\StatusCodes\\TryAgainLater',
    1015    => 'Phiws\\StatusCodes\\TlsHandshakeFailed',
    4000    => 'Phiws\\StatusCodes\\PrivateCode',
  ];

  // Array of 'ClassName' => object.
  protected static $defaultInstances = [];

  // HTTP code used when aborting pre-handshake connection.
  protected $httpCode = 500;

  static function mapCode($code, $class) {
    if ($code < 1000 or $code >= 5000) {
      CodeException::fail("mapCode($code): code is out of range");
    }

    static::$classes[$code] = $class;
  }

  static function codeClass($code) {
    if (isset(static::$classes[$code])) {
      return static::$classes[$code];
    }
  }

  static function defaultInstance() {
    $cls = get_called_class();
    $ref = &static::$defaultInstances[$cls];
    return $ref ?: $ref = new static;
  }

  static function fail($text = null, $httpCode = null, object $previous = null) {
    throw new static($text, $httpCode, $previous);
  }

  function __construct($text = null, $httpCode = null, object $previous = null) {
    isset($text) or $text = static::TEXT;
    parent::__construct($text, static::CODE, $previous);
    $this->httpCode($httpCode);
  }

  // 2-byte unsigned integer.
  function code() {
    return static::CODE;
  }

  // UTF-8.
  function text() {
    return $this->getMessage();
  }

  function defaultText() {
    return static::TEXT;
  }

  function describe() {
    return sprintf('%04d %s', $this->code(), $this->text());
  }

  function httpCode($code = null) {
    if (!isset($code)) {
      return $this->httpCode;
    } elseif ($code < 100 or $code >= 600) {
      CodeException::fail("httpCode($code): code is out of range");
    } else {
      $this->httpCode = (int) $code;
      return $this;
    }
  }

  function httpErrorHeaders() {
    $headers = new \Phiws\Headers\Bag;

    $status = new Headers\ResponseStatus($this->httpCode(), static::TEXT);
    $headers->status($status);

    $headers->set('Content-Type', 'text/plain; charset=utf-8');
    return $headers;
  }

  function httpErrorOutput() {
    $output = [
      $this->httpCode(),
      static::TEXT,
    ];

    if ($this->getMessage() !== static::TEXT) {
      $output[] = $this->text();
    }

    return $output;
  }
}
