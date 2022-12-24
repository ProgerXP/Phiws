<?php namespace Phiws;

class BaseTunnelTest extends \PHPUnit_Framework_TestCase {
  function testConstants() {
    $this->assertSame('CONNECTING', BaseTunnel::CONNECTING);
    $this->assertSame('OPEN', BaseTunnel::OPEN);
    $this->assertSame('CLOSED', BaseTunnel::CLOSED);

    $this->assertSame(13, BaseTunnel::WS_VERSION);

    $guid = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    $this->assertSame($guid, BaseTunnel::ACCEPT_KEY_GUID);

    // Length of new Sec-WebSocket-Key, in binary form.
    $this->assertSame(16, BaseTunnel::KEY_LENGTH);
  }

  /**
   * @dataProvider giveExpectedServerKey
   */
  function testExpectedServerKey($key, $expected) {
    $this->assertSame($expected, BaseTunnel::expectedServerKey($key));
  }

  function giveExpectedServerKey() {
    $guid = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    $key = Utils::randomKey(16);
    $value = base64_encode(sha1(base64_encode($key).$guid, true));

    return [
      [$key, $value],
      // Example value used in RFC 6455, pages 8 and 24.
      [base64_decode('dGhlIHNhbXBsZSBub25jZQ=='), 's3pPLMBiTxaQ9kYGzzhZRbK+xOo='],
    ];
  }

  // Example on page 18.
  //
  // The key is improperly encoded as '...EC==' in the RFC (see Errata 3150).
  // Input key is 16 bytes long, which makes it 2 bytes short for Base64 so
  // padding '==' is added. Last byte 0x10 is internally padded by 0x00 0x00.
  // It's encoded as a character with bit code 0b000100 ('E'), then one with
  // 0b000000 ('A'), then two '=' for padding. However, RFC encodes second
  // character with 'C' which stands for 0b000010. When decoding this produces
  // the same string because only first 8 bits of the tuple are used, and other
  // bits can be arbitrary (note that 1 Base64 character encodes 6 bits, so
  // second character plays a role). Therefore everything from ...EA== to ...EP==
  // results in the same source. This means the key used in the example can be
  // encoded in 16 different ways which will still result in the same key.
  // However, only a key encoded with pad bits set to 0 is Base64-RFC-compliant.
  function testKnownKey() {
    $key = hex2bin('0102030405060708090A0B0C0D0E0F10');
    $this->assertSame("AQIDBAUGBwgJCgsMDQ4PEA==", base64_encode($key));
  }

  function testGlobalPlugins() {
    $pi1 = new Plugins\Statistics;
    $pi2 = new Plugins\UserAgent;

    BaseTunnel::globalPlugins([$pi1, $pi2]);
    $this->assertSame([$pi1, $pi2], BaseTunnel::globalPlugins());

    BaseTunnel::globalPlugins([]);
    $this->assertSame([], BaseTunnel::globalPlugins());

    BaseTunnel::globalPlugins($pi1);
    $this->assertSame([$pi1], BaseTunnel::globalPlugins());

    BaseTunnel::globalPlugins($pi2);
    $this->assertSame([$pi1, $pi2], BaseTunnel::globalPlugins());
  }

  function testGlobalPluginsWrongArgument() {
    $this->expectException(CodeException::class);
    BaseTunnel::globalPlugins('watman');
  }
}
