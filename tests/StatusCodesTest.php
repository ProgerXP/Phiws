<?php namespace Phiws;

class StatusCodesTest extends \PHPUnit_Framework_TestCase {
  function giveDefinedCodes() {
    // Keys:
    // - code int: WebSocket registered code
    // - httpCode int: HTTP status code
    // - reserved bool: if inherits from ReservedCode
    // - preHandshake bool: if inherits from PreHandshakeCode
    // - (class str: automatic full class name of the status code)
    $classes = [
      'AbnormalClosure' => [
        'code'      => 1006,
        'reserved'  => true,
      ],

      'ClientExtensionsNotNegotiated' => [
        'code'      => 1010,
        'preHandshake' => true,
      ],

      'GoingAway' => [
        'code'      => 1001,
      ],
      
      'InternalError' => [
        'code'      => 1011,
      ],

      'InvalidHttpStatus' => [
        'httpCode'  => 500,
        'preHandshake' => true,
      ],

      'InvalidPayload' => [
        'code'      => 1007,
      ],

      'MalformedHttpHeader' => [
        'httpCode'  => 400,
        'preHandshake' => true,
      ],

      'MessageTooBig' => [
        'code'      => 1009,
      ],

      'NegotiationError' => [
        'httpCode'  => 400,
        'preHandshake' => true,
      ],

      'NormalClosure' => [
        'code'      => 1000,
      ],

      'NoStatusReceived' => [
        'code'      => 1005,
        'reserved'  => true,
      ],

      'PolicyViolation' => [
        'code'      => 1008,
      ],
     
      'ProtocolError' => [
        'code'      => 1002,
      ],

      'RequestUriMismatch' => [
        'httpCode'  => 404,
        'preHandshake' => true,
      ],

      'Reserved1004' => [
        'code'      => 1004,
        'reserved'  => true,
      ],

      'ServiceRestart' => [
        'code'      => 1012,
        'httpCode'  => 503,
      ],

      'TlsHandshakeFailed' => [
        'code'      => 1015,
        'httpCode'  => 412,
        'preHandshake' => true,
      ],

      'TryAgainLater' => [
        'code'      => 1013,
        'httpCode'  => 503,
      ],
     
      'UnsupportedData' => [
        'code'      => 1003,
      ],

      'UnsupportedHttpVersion' => [
        'httpCode'  => 505,
        'preHandshake' => true,
      ],

      'UnsupportedWebSocketVersion' => [
        'httpCode'  => 426,
        'preHandshake' => true,
      ],
    ];

    $res = [];

    foreach ($classes as $class => $props) {
      $res[] = [['class' => "Phiws\\StatusCodes\\$class"] + $props];
    }

    return $res;
  }

  /**
   * @dataProvider giveDefinedCodes
   */
  function testInheritance($class) {
    extract($class);

    $this->assertTrue( class_exists($class) );
    $this->assertTrue( is_subclass_of($class, 'Phiws\\StatusCode') );
    $this->assertTrue( is_subclass_of($class, 'Exception') );

    if (empty($preHandshake)) {
      // Pre-handshake errors can have no WS code since they are never transmitted
      // as a Close frame.
      $this->assertNotEmpty($class::CODE);
    }

    if (empty($reserved)) {
      $this->assertNotEmpty($class::TEXT);
    }

    $object = new $class;
    $this->assertEquals($object->code(), $class::CODE);
    $this->assertEquals($object->getMessage(), $class::TEXT);
    $defaultHttpCode = $object->httpCode();
    $this->assertNotEmpty($defaultHttpCode);
    $this->assertNull($object->getPrevious());

    $customMsg = uniqid();
    $object = new $class($customMsg);
    $this->assertEquals($object->code(), $class::CODE);
    $this->assertEquals($object->getMessage(), $customMsg);
    $this->assertEquals($object->text(), $customMsg);
    $this->assertSame($defaultHttpCode, $object->httpCode());
    $this->assertNull($object->getPrevious());

    $customHttpCode = 456;
    $prevEx = new \Exception;
    $object = new $class($customMsg, $customHttpCode, $prevEx);
    $this->assertEquals($object->code(), $class::CODE);
    $this->assertEquals($object->text(), $customMsg);
    $this->assertSame($customHttpCode, $object->httpCode());
    $this->assertSame($prevEx, $object->getPrevious());

    $customHttpCode = 234;
    $object->httpCode($customHttpCode);
    $this->assertSame($customHttpCode, $object->httpCode());
  }

  /**
   * @dataProvider giveDefinedCodes
   */
  function testProperties($class) {
    extract($class);

    isset($code) and $this->assertSame($code, $class::CODE);
    isset($text) and $this->assertSame($text, $class::TEXT);

    if (!empty($reserved)) {
      $this->assertTrue( is_subclass_of($class, 'Phiws\\StatusCodes\\ReservedCode') );
    }

    if (!empty($preHandshake)) {
      $this->assertTrue( is_subclass_of($class, 'Phiws\\StatusCodes\\PreHandshakeCode') );
    }

    $object = new $class;

    isset($httpCode) and $this->assertSame($httpCode, $object->httpCode());
  }

  /**
   * @dataProvider giveDefinedCodes
   */
  function testFail($class) {
    extract($class);

    $customMsg = uniqid();
    $customHttpCode = mt_rand(100, 599);
    $prevEx = new \Exception;

    try {
      $class::fail($customMsg, $customHttpCode, $prevEx);
      $this->fail();
    } catch (\Exception $e) {
      $this->assertSame($class, get_class($e));

      $this->assertSame($class::CODE, $e->code());

      $this->assertSame($customMsg, $e->text());
      $this->assertSame($customMsg, $e->getMessage());
      $this->assertSame($customMsg, $e->text());

      $this->assertSame($customHttpCode, $e->httpCode());

      $this->assertSame($prevEx, $e->getPrevious());
    }
  }

  /**
   * @dataProvider giveAbstract
   */
  function testAbstract($class) {
    $class = "Phiws\\StatusCodes\\$class";
    $ref = new \ReflectionClass($class);
    $this->assertTrue($ref->isAbstract());
  }

  function giveAbstract() {
    return [['ReservedCode']];
  }

  function testPrivate() {
    $class = 'Phiws\\StatusCodes\\PrivateCode';

    $msg = uniqid();
    $code = 4444;
    $prevEx = new \Exception;
    $object = new $class($msg, $code, $prevEx);

    $this->assertSame($msg, $object->getMessage());
    $this->assertSame($msg, $object->text());

    $this->assertSame($code, $object->code());
    $this->assertSame(500, $object->httpCode());

    $this->assertSame($prevEx, $object->getPrevious());
  }

  function testPrivateCodeRange() {
    $class = 'Phiws\\StatusCodes\\PrivateCode';

    $this->assertSame(4000, $class::START);
    $this->assertSame(4999, $class::END);

    foreach ([$class::START, $class::END] as $code) {
      $object = new $class('x', $code);

      $this->assertSame($code, $object->code());
      $this->assertSame(500, $object->httpCode());
    }

    foreach ([$class::START - 1, $class::END + 1] as $code) {
      try {
        new $class('x', $code);
        $this->fail();
      } catch (\Exception $e) {
        $this->assertContains('out of range', $e->getMessage());
      }
    }
  }

  function testCodeException() {
    $class = 'Phiws\\CodeException';

    $this->assertTrue( is_subclass_of($class, 'Phiws\\StatusCode') );
  }

  function testHttpCodeGetSet() {
    $object = new CodeException;

    $old = $object->httpCode();
    $this->assertSame($old, $object->httpCode());

    $this->assertSame($old, $object->httpCode(null));
    $this->assertSame($old, $object->httpCode());

    $new = mt_rand(100, 599);
    $object->httpCode($new);
    $this->assertSame($new, $object->httpCode());
    $this->assertSame($new, $object->httpCode());

    $object->httpCode($new);
    $this->assertSame($new, $object->httpCode());

    foreach (range(100, 599) as $new) {
      $object->httpCode($new);
      $this->assertSame($new, $object->httpCode());
    }
  }

  /**
   * @dataProvider giveBadHttpCodeSet
   */
  function testBadHttpCodeSet($code) {
    $this->expectException('Phiws\\CodeException');
    (new CodeException)->httpCode($code);
  }

  function giveBadHttpCodeSet() {
    return [[99], [600], [-1], [PHP_INT_MAX]];
  }

  function testMapCode() {
    $code = 4512;
    $this->assertNull(StatusCode::codeClass($code));

    StatusCode::mapCode($code, 'Foox');
    $this->assertSame('Foox', StatusCode::codeClass($code));

    $this->assertSame(StatusCodes\InternalError::class, StatusCode::codeClass(1011));
    StatusCode::mapCode(1011, 'Zaebox');
    $this->assertSame('Zaebox', StatusCode::codeClass(1011));

    StatusCode::mapCode(1011, StatusCodes\InternalError::class);
  }

  /**
   * @dataProvider giveMapCodeOutOfRange
   */
  function testMapCodeOutOfRange($code) {
    $this->expectException(CodeException::class);
    $this->expectExceptionMessage('out of range');
    StatusCode::mapCode($code, 'x');
  }

  function giveMapCodeOutOfRange() {
    return [
      [-PHP_INT_MAX], [-1], [0], [1],
      [500], [999],
      [5000], [5001], [PHP_INT_MAX],
    ];
  }

  /** 
   * @dataProvider giveDefinedCodes
   */
  function testCodeClass($class) {
    extract($class);

    if (isset($code)) {
      $this->assertSame($class, StatusCode::codeClass($code));
    }
  }

  function testCodeClassUnlisted() {
    $this->assertNull(StatusCode::codeClass(4444));
  }
}
