<?php namespace Phiws;

use Phiws\Maskers\Xor32;

class Maskers_Xor32Test extends \PHPUnit_Framework_TestCase {
  function testRandomKey() {
    $key = Xor32::randomKey();
    $this->assertTrue(is_int($key) or is_float($key));
    $this->assertTrue($key > 0);

    $this->assertNotSame($key, Xor32::randomKey());
  }

  function testWithNewKey() {
    $with1 = Xor32::withNewKey();
    $with2 = Xor32::withNewKey();

    $key = $with1->key();
    $this->assertTrue(is_int($key) or is_float($key));
    $this->assertTrue($key > 0);

    $this->assertNotSame($with1->key(), $with2->key());
  }

  /**
   * @dataProvider giveConstructorOutOfRange
   */
  function testConstructorOutOfRange($key) {
    $this->expectException('Phiws\\CodeException');
    $this->expectExceptionMessage('out of range');
    $xor = new Xor32($key);
  }

  function giveConstructorOutOfRange() {
    return [[-1], [0], [pow(2, 32)], [null], ['123'], ['zoo']];
  }

  function testConstructor() {
    $key = mt_rand();
    $xor = new Xor32($key);
    $this->assertSame($key, $xor->key());

    $xor = new Xor32(pow(2, 32) - 1);
    $this->assertSame(pow(2, 32) - 1, $xor->key());
  }

  /**
   * @dataProvider giveMaskKnownData
   */
  function testMaskKnownData($key, $data, $expected) {
    $this->assertSame($expected, static::slowXOR($key, $data));
    
    (new Xor32($key))->mask($data);
    $this->assertSame($expected, $data);
  }

  function giveMaskKnownData() {
    return [
      [
        0x8644FB16,
        str_repeat("\xEB\x3D\xA5\xC0", 4),
        str_repeat("\x6D\x79\x5E\xD6", 4),
      ],
      // RFC, page 38.
      [
        0x37FA213D,
        hex2bin('7F9F4D5158'),
        'Hello',
      ],
    ];
  }

  /**
   * @dataProvider giveMask
   */
  function testMask($unmask, $key, $data, $expectedData) {
    $func = $unmask ? 'unmask' : 'mask';
    (new Xor32($key))->$func($data);
    $this->assertTrue($data === $expectedData);
  }

  function giveMask() {
    $res = [];

    foreach ([true, false] as $unmask) {
      for ($n = 0; $n < 10; $n++) {
        $data = Utils::randomKey(1024);
        $key = mt_rand() << mt_rand(0, 1);
        $res[] = [$unmask, $key, $data, static::slowXOR($key, $data)];
      }
    }


    return $res;
  }

  function testMaskLong() {
    // Uneven data that's longer than mask chunk.
    $data = Utils::randomKey(Xor32::CHUNK_SIZE * 8 + 13);
    $key = Utils::randomKey(4);
    $fullKey = str_repeat($key, ceil(strlen($data) / 4));
    $expected = $data ^ $fullKey;

    list(, $intKey) = unpack('N', $key);
    (new Xor32($intKey))->mask($data);

    $this->assertTrue($expected === $data);
  }

  // Slow bullet-proof char-by-char iteration.
  static function slowXOR($key, $data) {
    if (!is_int($key)) { throw new \Exception('$key is not an integer'); }

    $key = pack('N', $key);
    $expectedData = '';

    for ($i = 0; isset($data[$i]); $i++) {
      $expectedData .= chr( ord($data[$i]) ^ ord($key[$i % 4]) );
    }

    return $expectedData;
  }

  function testUpdateHeader() {
    $header = new FrameHeader;
    $this->assertFalse($header->mask);
    $this->assertNull($header->maskingKey);

    $masker = Xor32::withNewKey();
    $keyStr = pack('N', $masker->key());

    $masker->updateHeader($header);
    $this->assertTrue($header->mask);
    $this->assertSame($keyStr, $header->maskingKey);
  }
}
