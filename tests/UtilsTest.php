<?php namespace Phiws;

class UtilsTest extends \PHPUnit_Framework_TestCase {
  function testPhpUnit() {
    $this->assertSame(-1, error_reporting(), 'maximum level of error_reporting() is required');

    try {
      list($a) = [];
      $this->fail();
    } catch (\PHPUnit_Framework_Exception $e) {
      $this->assertContains('offset', $e->getMessage());
    }
  }

  /**
   * @dataProvider giveNewTempStream
   */
  function testNewTempStream($limit) {
    // Can't think of a way to tell apart php://memory and php://temp, other
    // than causing out-of-memory error which terminates PHPUnit.
    Utils::$tempStreamLimit = $limit;
    $handle = Utils::newTempStream();
    $this->assertTrue(is_resource($handle));

    fwrite($handle, str_repeat('a', 10 * 1024 * 1024));
    fclose($handle);
  }

  function giveNewTempStream() {
    return [[false], [1024], [1024 * 1024 * 1024]];
  }

  function testFcloseAndNull() {
    $handle = $handleCopy = fopen('php://memory', 'r');
    Utils::fcloseAndNull($handle);
    $this->assertNull($handle);
    $this->assertFalse(is_resource($handleCopy));

    $nonHandle = 'booruu';
    Utils::fcloseAndNull($nonHandle);
    $this->assertNull($nonHandle);

    $closedHandle = fopen('php://memory', 'r');
    fclose($closedHandle);
    $this->assertFalse(is_resource($closedHandle));
    Utils::fcloseAndNull($closedHandle);
    $this->assertNull($nonHandle);
  }

  /**
   * @dataProvider giveRandomKeyZeroLength
   */
  function testRandomKeyZeroLength($len) {
    $this->expectException('Phiws\\CodeException');
    Utils::randomKey($len);
  }

  function giveRandomKeyZeroLength() {
    return [[0], [-1], [null], ['nan']];
  }

  /**
   * @dataProvider giveRandomKey
   */
  function testRandomKey($len, $mech) {
    $res = Utils::randomKey($len, $mech);
    $this->assertInternalType('string', $res);
    $this->assertSame($len, strlen($res));

    $new = Utils::randomKey($len, $mech);
    $len > 1 and $this->assertNotSame($new, $res);
    $this->assertSame($len, strlen($new));

    // Making sure it returns something else than a string of only NULs.
    for ($try = 3; ; $try--) {
      if (ltrim($res, "\0") !== '') {
        break;
      } elseif ($try <= 0) {
        $this->fail();
      }

      $res = Utils::randomKey($len, $mech);
    }
  }

  function giveRandomKey() {
    $availableMechanisms = ['php', 'openssl', 'dev', 'mt_rand'];

    $res = [];

    foreach ([1, 2, 5, 10, 32, 64, 100, 128] as $len) {
      foreach ($availableMechanisms as $mech) {
        $res[] = [$len, $mech];
      }
    }

    return $res;
  }
}
