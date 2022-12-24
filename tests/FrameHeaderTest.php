<?php namespace Phiws;

class FrameHeaderTest extends \PHPUnit_Framework_TestCase {
  function testDefaults() {
    $header = new FrameHeader;

    $this->assertFalse($header->fin);
    $this->assertFalse($header->rsv1);
    $this->assertFalse($header->rsv2);
    $this->assertFalse($header->rsv3);
    $this->assertSame(0, $header->opcode);
    $this->assertFalse($header->mask);
    $this->assertSame(0, $header->payloadLength);
    $this->assertNull($header->maskingKey);
  }

  /**
   * @dataProvider giveLengthToBits
   */
  function testLengthToBits($length, $result) {
    $result = str_split($result);
    $this->assertSame($result, FrameHeader::lengthToBits($length));
  }

  function giveLengthToBits() {
    return [
      [0,   "\0"],
      [1,   "\1"],
      [125, "\x7D"],

      [126,     "\x7E\0\x7E"],
      [255,     "\x7E\0\xFF"],
      [256,     "\x7E\1\0"],
      [0xFFFF,  "\x7E\xFF\xFF"],

      [0x10000, "\x7F\0\0\0\0\0\1\0\0"],
      [0xFFFFF, "\x7F\0\0\0\0\0\x0F\xFF\xFF"],
      [0x7FFFFFFFFFFFFFFF, "\x7F\x7F\xFF\xFF\xFF\xFF\xFF\xFF\xFF"],
    ];
  }

  /**
   * @dataProvider giveLengthToBits
   */
  function testBitsToLength($length, $result) {
    $consumed = strlen($result);
    $result .= $this->randomChars();
    list($resLength, $resConsumed) = FrameHeader::bitsToLength($result);
    $this->assertSame($consumed, $resConsumed);
    $this->assertSame($length, $resLength);
  }

  /**
   * @dataProvider giveBitsToLengthShort
   */
  function testBitsToLengthShort($str) {
    $this->expectException(Exceptions\NotEnoughInput::class);
    FrameHeader::bitsToLength($str);
  }

  function giveBitsToLengthShort() {
    return [
      ["\x7E"], ["\x7E\x11"],
      ["\x7F"], ["\x7F\x11"], ["\x7F\x11\x22"],
      ["\x7F\x11\x22\x33"],
      ["\x7F\x11\x22\x33\x44"],
      ["\x7F\x11\x22\x33\x44\x55"],
      ["\x7F\x11\x22\x33\x44\x55\x66"],
      ["\x7F\x11\x22\x33\x44\x55\x66\x77"],
    ];
  }

  /**
   * @dataProvider giveBitsToLengthInvalidFirstByte
   */
  function testBitsToLengthInvalidFirstByte($byte) {
    $this->expectException('Phiws\\CodeException');
    $this->expectExceptionMessage('first byte');

    FrameHeader::bitsToLength($byte.$this->randomChars());
  }

  function giveBitsToLengthInvalidFirstByte() {
    $res = range("\x80", "\xFF");
    foreach ($res as &$ref) { $ref = [$ref]; }
    return $res;
  }

  function testRandomChars() {
    for ($i = 0; $i < 50; $i++) {
      $this->assertSame(5, strlen($this->randomChars(5, 5)));

      $len = strlen($this->randomChars(1, 10));
      $this->assertTrue(($len >= 1) and ($len <= 10));
    }
  }

  function randomChars($min = 0, $max = 10) {
    $res = '';

    for ($i = mt_rand($min, $max); $i > 0; $i--) {
      $res .= chr(mt_rand(0, 255));
    }

    return $res;
  }

  function testLengthToBitsNegative() {
    $this->expectException('Phiws\\CodeException');
    $this->expectExceptionMessage('negative');
    FrameHeader::lengthToBits(-1);
  }

  function testLengthToBitsTooBig() {
    $this->expectException(StatusCodes\MessageTooBig::class);
    FrameHeader::lengthToBits(0x8000000000000000);
  }

  function testBuildOpcodeOutOfRange() {
    $header = new FrameHeader;
    $header->opcode = 16;

    $this->expectException('Phiws\\CodeException');
    $this->expectExceptionMessage('opcode is out of range');

    $header->build();
  }

  /**
   * @dataProvider giveBuild
   */
  function testBuild($result, array $attributes) {
    $header = new FrameHeader;

    foreach ($attributes as $key => $value) {
      $header->$key = $value;
    }

    $this->assertSame(bin2hex($result), bin2hex($header->build()));
  }

  function giveBuild() {
    return [
      ["\0\0", []],

      ["\x80\0", ['fin' => true]],
      ["\x40\0", ['rsv1' => true]],
      ["\x20\0", ['rsv2' => true]],
      ["\x10\0", ['rsv3' => true]],
      ["\x01\0", ['opcode' => 1]],
      ["\x08\0", ['opcode' => 8]],
      ["\x0F\0", ['opcode' => 15]],

      ["\0\x80", ['mask' => true]],
      ["\0\x01", ['payloadLength' => 1]],
      ["\0\x81", ['mask' => true, 'payloadLength' => 1]],
      ["\0\x7D", ['payloadLength' => 125]],
      ["\0\xFD", ['mask' => true, 'payloadLength' => 125]],

      ["\0\x7E\xFF\x98", [
        'payloadLength' => 65432,
      ]],

      ["\0\xFE\xFF\x98", [
        'mask' => true,
        'payloadLength' => 65432,
      ]],

      ["\x40\xFF\0\0\0\0\0\1\0\2", [
        'rsv1' => true,
        'mask' => true,
        'payloadLength' => 65538,
      ]],

      ["\0\x7E\xFF\x98", [
        'payloadLength' => 65432,
        'maskingKey' => "\xBE\xED",
      ]],

      ["\0\xFE\xFF\x98\xBE\xED\xAA\x99", [
        'mask' => true,
        'payloadLength' => 65432,
        'maskingKey' => "\xBE\xED\xAA\x99",
      ]],

      ["\xF0\x80", [
        'fin' => true,
        'rsv1' => true,
        'rsv2' => true,
        'rsv3' => true,
        'mask' => true,
      ]],

      ["\xFF\0", [
        'fin' => true,
        'rsv1' => true,
        'rsv2' => true,
        'rsv3' => true,
        'opcode' => 15,
      ]],
    ];
  }

  /**
   * @dataProvider giveBuild
   */
  function testParse($result, array $attributes) {
    $header = new FrameHeader;
    $defaults = (array) $header;

    foreach ($attributes as $attr => $value) {
      $default = is_int($value) ? 0 : (is_bool($value) ? false : null);
      $this->assertSame($default, $defaults[$attr], $attr);
    }

    if (empty($attributes['mask'])) {
      $attributes['maskingKey'] = null;
    } elseif (!isset($attributes['maskingKey'])) {
      $result .= $maskingKey = $this->randomChars(4, 4);
      $attributes['maskingKey'] = $maskingKey;
    }

    $consumed = strlen($result);
    $result .= $this->randomChars();

    $resConsumed = $header->parse($result);
    $this->assertSame($consumed, $resConsumed);

    foreach ($defaults as $attr => $value) {
      array_key_exists($attr, $attributes) and $value = $attributes[$attr];
      $this->assertSame($value, $header->$attr);
    }
  }

  /**
   * @dataProvider giveParseShort
   */
  function testParseShort($str, $msg) {
    $header = new FrameHeader;

    $this->expectException(Exceptions\NotEnoughInput::class);
    $this->expectExceptionMessage($msg);

    $header->parse($str);
  }

  function giveParseShort() {
    return [
      ["", 'header'], ["\0", 'header'], ["\1", 'header'],
      ["\0\x80", 'maskingKey'], ["\0\xBB", 'maskingKey'], 
      ["\0\xFE\1\2", 'maskingKey'],
    ];
  }

  /**
   * @dataProvider giveParseShortLengthBits
   */
  function testParseShortLengthBits($str) {
    $header = new FrameHeader;
    $this->expectException(Exceptions\NotEnoughInput::class);
    $header->parse($str);
  }

  function giveParseShortLengthBits() {
    $res = [];

    foreach ($this->giveBitsToLengthShort() as $args) {
      list($str) = $args;
      $bits = ord($str);

      $res[] = [chr(mt_rand(0, 255)).chr($bits)];
      $res[] = [chr(mt_rand(0, 255)).chr($bits + 0x80)];
    }

    return $res;
  }

  function testDescribe() {
    $header = new FrameHeader;
    $this->assertSame('Cont (-)', $header->describe());

    $opcodes = [
      1  => 'Text',
      2  => 'Data',
      8  => 'Clos',
      9  => 'Ping',
      10 => 'Pong',
    ];

    foreach ($opcodes as $opcode => $caption) {
      $header->opcode = $opcode;
      $this->assertSame("$caption (-)", $header->describe());
    }

    $header->opcode = 0x0E;
    $this->assertSame("0x0E (-)", $header->describe());

    $header->opcode = 0xFE;
    $header->rsv2 = true;
    $header->mask = true;
    $header->payloadLength = 0xFEEE;
    $this->assertSame('0xFE (2M) [65262]', $header->describe());
    $this->assertSame('0xFE (2M) [65262]', (string) $header);
  }

  // RFC, page 39.
  function testKnownBinaryFrameHeader() {
    $data = hex2bin('827E0100')."x";

    $header = new FrameHeader;
    $offset = $header->parse($data);
    $this->assertSame(4, $offset);
    $this->assertSame(256, $header->payloadLength);
    $this->assertTrue($header->fin);
    $this->assertFalse($header->mask);

    $data = hex2bin('827F0000000000010000')."x";

    $header = new FrameHeader;
    $offset = $header->parse($data);
    $this->assertSame(10, $offset);
    $this->assertSame(65536, $header->payloadLength);
    $this->assertTrue($header->fin);
    $this->assertFalse($header->mask);
  }
}
