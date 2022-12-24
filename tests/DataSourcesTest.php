<?php namespace Phiws;

use Phiws\DataSources\String as DSString;
use Phiws\DataSources\Stream as DSStream;
use Phiws\Exceptions\EStream;

class DataSourcesTest extends \PHPUnit_Framework_TestCase {
  /**
   * @dataProvider giveStream
   */
  function testStream($seekOnStart) {
    $h = fopen('php://memory', 'w+b');
    fwrite($h, join(range(0, 9)));
    $this->assertSame('0123456789', stream_get_contents($h, -1, 0));

    fseek($h, $seekOnStart ? 5 : 0);
    $src = new DSStream($h, false);
    $this->assertFalse($src->autoClose());

    $this->assertSame($h, $src->handle());
    $this->assertSame(10, $src->size());

    $this->assertSame('0', $src->readHead(1));
    $this->assertSame(10, $src->size());
    $this->assertSame('01', $src->readHead(2));
    $this->assertSame(10, $src->size());

    $h2 = fopen('php://memory', 'w+b');
    $src->readHead(5);
    $src->copyTo($h2);
    $this->assertSame('0123456789', stream_get_contents($h2, -1, 0));
    ftruncate($h2, 0);
    $src->copyTo($h2);
    $this->assertSame('0123456789', stream_get_contents($h2, -1, 0));
    fclose($h2);

    $this->assertSame(10, $src->size());
    $this->assertSame('0123456789', $src->readAll());

    $this->assertTrue(is_resource($h));
    $src->close();
    $this->assertFalse(is_resource($h));

    $src->close();
    $src->close();
    $src->close();
  }

  function giveStream() {
    return [[true], [false]];
  }

  /**
   * @dataProvider giveStreamAutoClose
   */
  function testStreamAutoClose($autoClose) {
    $h = fopen('php://memory', 'w+b');
    $src = new DSStream($h, $autoClose);
    $this->assertSame($autoClose !== false, $src->autoClose());
    $this->assertTrue(is_resource($h));

    if ($autoClose === 'both') {
      // Both close() and __destruct().
      $src->close();
    }

    $src = null;
    unset($src);

    if ($autoClose) {
      $this->assertFalse(is_resource($h));
    } else {
      $this->assertTrue(is_resource($h));
      fclose($h);
    }
  }

  function giveStreamAutoClose() {
    return [[true], [false], ['both']];
  }

  /**
   * @dataProvider giveStreamAutoCloseOnException
   */
  function testStreamAutoCloseOnException($autoClose) {
    $h = fopen('php://memory', 'w+b');

    try {
      call_user_func(function () use ($autoClose, $h) {
        (new DSStream($h, $autoClose))->readHead(-1);
      });

      $this->fail();
    } catch (CodeException $e) {
      $this->assertSame(!$autoClose, is_resource($h));
      $autoClose or fclose($h);
    }
  }

  function giveStreamAutoCloseOnException() {
    return [[true], [false]];
  }

  /**
   * @dataProvider giveStreamWrongHandle
   */
  function testStreamWrongHandle($value) {
    $this->expectException('Phiws\\CodeException');
    $this->expectExceptionMessage('not a resource');
    new DSStream($value, false);
  }

  function giveStreamWrongHandle() {
    fclose($h = fopen('php://memory', 'w'));
    return [[null], [$h], [0], [1], [true], [[]]];
  }

  function testStreamEmpty() {
    $h = fopen('php://memory', 'w+b');
    $src = new DSStream($h, true);
    $this->assertSame('', $src->readAll());
    $this->assertSame(0, $src->size());

    $h2 = fopen('php://memory', 'w+b');
    $src->copyTo($h2);
    $this->assertSame(0, ftell($h));
    $this->assertSame(0, ftell($h2));
    $this->assertSame(0, $src->size());
    fclose($h2);

    fclose($h);
  }

  /**
   * @dataProvider giveStreamAfterClose
   */
  function testStreamAfterClose($func, $args) {
    $h = fopen('php://memory', 'w+b');
    $src = new DSStream($h, true);
    $src->close();

    $this->expectException('PHPUnit_Framework_Error');
    call_user_func_array([$src, $func], $array);
  }

  function giveStreamAfterClose() {
    return [
      ['copyTo', [fopen('php://memory', 'w')]],
      ['readHead', [5]],
      ['seek', []],
    ];
  }

  function testStreamRead0() {
    $h = fopen('php://memory', 'w+b');
    $src = new DSStream($h, true);
    $this->assertSame('', $src->readHead(0));
  }

  function testStreamReadNeg() {
    $h = fopen('php://memory', 'w+b');
    $src = new DSStream($h, true);

    $this->expectException(CodeException::class);
    $this->expectExceptionMessage('size');
    $src->readHead(-1);
  }

  /**
   * @dataProvider giveStreamCopyToNotAHandle
   */
  function testStreamCopyToNotAHandle($value) {
    $h = fopen('php://memory', 'w+b');
    fwrite($h, '123');

    $this->expectException(EStream::class);
    (new DSStream($h, true))->copyTo($value);
  }

  function giveStreamCopyToNotAHandle() {
    return [[null], [[]], [true]];
  }

  function testStreamCopyToReadOnly() {
    $h = fopen('php://memory', 'w+b');
    fwrite($h, '123');

    $this->expectException(EStream::class);
    (new DSStream($h, true))->copyTo(fopen('php://memory', 'r'));
  }

  function testStreamCopyToOffsetAndLength() {
    $h = fopen('php://memory', 'w+b');
    fwrite($h, '0123456789');
    $src = new DSStream($h, true);

    $h2 = fopen('php://memory', 'w+b');

    $src->seek(2);
    $src->copyTo($h2, 1);
    $this->assertSame('123456789', stream_get_contents($h2, -1, 0));

    ftruncate($h2, 0);
    $src->seek(8);
    $src->copyTo($h2, 3, 2);
    $this->assertSame('34', stream_get_contents($h2, -1, 0));

    ftruncate($h2, 0);
    $src->seek(1);
    $src->copyTo($h2, 0, pow(2, 31));
    $this->assertSame('0123456789', stream_get_contents($h2, -1, 0));
  }

  /**
   * @dataProvider giveStreamCopyToNegOffset
   */
  function testStreamCopyToNegOffset($value) {
    $h = fopen('php://memory', 'w+b');
    fwrite($h, '0123456789');

    $h2 = fopen('php://memory', 'w+b');

    $src = new DSStream($h, true);
    $src->seek(2);
    $src->copyTo($h2, $value);

    $this->assertSame('0123456789', stream_get_contents($h2, -1, 0));
  }

  function giveStreamCopyToNegOffset() {
    return [[-5], [-1], [0]];
  }

  /**
   * @dataProvider giveStreamCopyToNegLength
   */
  function testStreamCopyToNegLength($value) {
    $h = fopen('php://memory', 'w+b');
    fwrite($h, '0123456789');

    $h2 = fopen('php://memory', 'w+b');

    $src = new DSStream($h, true);
    $src->seek(2);
    $src->copyTo($h2, 5, $value);

    $this->assertSame('56789', stream_get_contents($h2, -1, 0));
  }

  function giveStreamCopyToNegLength() {
    return [[-5], [-1]];
  }

  function testStreamCopyEx() {
    $h = fopen('php://memory', 'w+b');
    fwrite($h, '0123456789');
    $src = new DSStream($h, true);

    $h2 = fopen('php://memory', 'w+b');

    $offset = 1;
    $this->assertSame(3, $src->copyEx($h2, $offset, 3));
    $this->assertSame('123', stream_get_contents($h2, -1, 0));
    $this->assertSame(4, $offset);

    ftruncate($h2, 0);
    fseek($h, 2);
    $offset = 1;
    $this->assertSame(5, $src->copyEx($h2, $offset, 5));
    $this->assertSame('12345', stream_get_contents($h2, -1, 0));
    $this->assertSame(6, $offset);

    $this->assertSame(4, $src->copyEx($h2, $offset, 50));
    $this->assertSame('123456789', stream_get_contents($h2, -1, 0));
    $this->assertSame(10, $offset);

    for ($i = 0; $i < 3; $i++) {
      $this->assertSame(0, $src->copyEx($h2, $offset, 1));
      $this->assertSame('123456789', stream_get_contents($h2, -1, 0));
      $this->assertSame(10, $offset);
    }
  }

  /**
   * @dataProvider giveStreamCopyBig
   */
  function testStreamCopyBig($copyEx) {
    $src = DSStream::newTemporary();
    $str = Utils::randomKey($size = 1024 * 1024 + 27);
    fwrite($src->handle(), $str);
    $src->updateSize();
    $this->assertSame($size, $src->size());

    $h2 = fopen('php://memory', 'w+b');

    $offset = 0;
    $len = $copied = null;

    $src->seek(5);

    while (true) {
      if ($copyEx) {
        $copied = $src->copyEx($h2, $offset, $len = mt_rand());
      } else {
        $copied = $src->copyTo($h2, $offset, $len = mt_rand());
        $offset += $copied;
      }

      // All reads except last one must be of the same length as requested.
      if ($copied !== $len) {
        if (ftell($h2) === $size) {
          break;
        } else {
          // Fails.
          $this->assertSame($copied, $len);
        }
      }
    }

    $this->assertSame($size, $offset);
    $this->assertSame($size, ftell($h2));
    $this->assertTrue($str === stream_get_contents($h2, -1, 0));
  }

  function giveStreamCopyBig() {
    return [[false], [true]];
  }

  function testStreamReadChunks() {
    $h = fopen('php://memory', 'w+b');
    $str = Utils::randomKey(1024 * 1024 + 27);
    fwrite($h, $str);
    $src = new DSStream($h, true);

    $src->seek(5);

    $i = 0;
    $src->readChunks($chunkSize = 11173, function ($chunk) use ($chunkSize, &$i, &$str) {
      $expected = (string) substr($str, $i++ * $chunkSize, $chunkSize);
      $this->assertSame(strlen($expected), strlen($chunk));
      $this->assertTrue($expected === $chunk);
    });

    $this->assertSame((int) ceil(strlen($str) / $chunkSize), $i);
  }

  function testStreamReadChunksSmall() {
    $h = fopen('php://memory', 'w+b');
    fwrite($h, '01234');
    $src = new DSStream($h, true);

    $src->seek(1);

    $i = 0;
    $src->readChunks(11173, function ($chunk) use (&$i) {
      $this->assertSame(0, $i++);
      $this->assertSame('01234', $chunk);
    });

    $this->assertSame(1, $i);
  }

  /**
   * @dataProvider giveStreamReadChunksNotPositive
   */
  function testStreamReadChunksNotPositive($value) {
    $this->expectException(CodeException::class);
    $this->expectExceptionMessage('positive');
    DSStream::newTemporary()->readChunks($value, function () { });
  }

  function giveStreamReadChunksNotPositive() {
    return [[0], [-1], [-5]];
  }

  function testStreamSeek() {
    $h = fopen('php://memory', 'w+b');
    fwrite($h, '0123456789');
    $src = new DSStream($h, true);

    $src->seek(5);
    $this->assertSame(5, ftell($h));

    $src->seek();
    $this->assertSame(0, ftell($h));

    $src->seek(10);
    $this->assertSame(10, ftell($h));
    
    $src->seek(-3, SEEK_END);
    $this->assertSame(7, ftell($h));
    
    $src->seek(-1, SEEK_CUR);
    $this->assertSame(6, ftell($h));
    
    $src->seek(3, SEEK_CUR);
    $this->assertSame(9, ftell($h));
  }

  function testStreamUpdateSize() {
    $h = fopen('php://memory', 'w+b');
    fwrite($h, '0123456789');
    $src = new DSStream($h, true);

    $this->assertSame(10, $src->size());
    fwrite($h, '...');
    $this->assertSame(10, $src->size());
    $src->updateSize();
    $this->assertSame(13, $src->size());

    fwrite($h, '--');   
    fseek($h, -4, SEEK_CUR);
    $src->updateSize();
    $src->updateSize();
    $this->assertSame(15, $src->size());
  }

  function randomForCompression() {
    $len = 1024 * 1024;
    $res = '';

    while (!isset($res[$len])) {
      $res .= str_repeat(chr(mt_rand(0, 255)), mt_rand(1, 10));
    }

    return $res;
  }

  /**
   * @dataProvider giveStreamCopyFiltered
   */
  function testStreamCopyFiltered($func, $length) {
    $uncomp = $this->randomForCompression();
    $comp = gzdeflate($uncomp);
    $this->assertTrue(strlen($uncomp) > strlen($comp));

    $h = fopen('php://memory', 'w+b');
    fwrite($h, $uncomp);
    $src = new DSStream($h, true);
    $this->assertSame(strlen($uncomp), $src->size());

    $h2 = fopen('php://memory', 'w+b');

    /* 1 */
    $filter = stream_filter_append($h2, 'zlib.deflate', STREAM_FILTER_WRITE);

    $offset = 0;
    $args = [$h2, &$offset];

    $length === 'uncomp' and $length = strlen($uncomp);
    isset($length) and $args[] = $length;

    $res = call_user_func_array([$src, $func], $args);
    $this->assertSame(strlen($uncomp), $res);

    if ($func === 'copyEx') {
      $this->assertSame(strlen($uncomp), $offset);
    }

    stream_filter_remove($filter);
    rewind($h2);
    $this->assertTrue($comp === stream_get_contents($h2, -1, 0));

    /* 2 */
    ftruncate($h2, 0);
    $filter = stream_filter_append($h2, 'zlib.deflate', STREAM_FILTER_WRITE);

    $offset = 0;
    $res = call_user_func_array([$src, $func], [$h2, &$offset, 10]);
    $this->assertSame(10, $res);

    if ($func === 'copyEx') {
      $this->assertSame(10, $offset);
    } else {
      $offset = 10;
    }

    call_user_func_array([$src, $func], [$h2, &$offset]);

    stream_filter_remove($filter);
    rewind($h2);
    $this->assertTrue($comp === stream_get_contents($h2, -1, 0));
  }

  function giveStreamCopyFiltered() {
    return [
      ['copyTo', null],
      ['copyTo', -1],
      ['copyTo', -10],
      ['copyTo', 'uncomp'],
      ['copyTo', PHP_INT_MAX],

      ['copyEx', null],
      ['copyEx', -1],
      ['copyEx', -10],
      ['copyEx', 'uncomp'],
      ['copyEx', PHP_INT_MAX],
    ];
  }

  function testString() {
    $src = new DSString(join(range(0, 9)));
    $this->assertSame(10, $src->size());

    $this->assertSame('01234', $src->readHead(5));
    $this->assertSame(10, $src->size());

    $h = fopen('php://memory', 'w+b');

    $src->copyTo($h);
    $this->assertSame(10, $src->size());
    $this->assertSame('01', $src->readHead(2));

    $this->assertSame('0123456789', stream_get_contents($h, -1, 0));

    ftruncate($h, 0);
    fseek($h, 0);
    $src->copyTo($h);
    $this->assertSame(10, $src->size());
    $this->assertSame(10, ftell($h));
    
    fclose($h);

    $this->assertSame('0123456789', $src->readHead(100));
    $this->assertSame(10, $src->size());
    $this->assertSame('0123456789', (string) $src);

    $src->close();
    $this->assertSame('', $src->readHead(1));
    $this->assertSame(0, $src->size());
    $this->assertSame('', (string) $src);
  }

  function testStringConstructor() {
    $src = new DSString(0x1E240);
    $this->assertSame(6, $src->size());
    $this->assertSame('123456', (string) $src);
    $this->assertSame('12345', $src->readHead(5));
    $this->assertSame(6, $src->size());
  }

  function testStringConstructorArray() {
    $this->expectException('PHPUnit_Framework_Error');
    new DSString([]);
  }

  function testStringRead0() {
    $src = new DSString('0123456789');
    $this->assertSame('', $src->readHead(0));
    $this->assertSame('0', $src->readHead(1));
    $this->assertSame('01', $src->readHead(2));
  }

  /**
   * @dataProvider giveStreamCopyToNotAHandle
   */
  function testStringCopyToNotAHandle($value) {
    $this->expectException(EStream::class);
    (new DSString('123'))->copyTo($value);
  }

  function testStringCopyToReadOnly() {
    $this->expectException(EStream::class);
    (new DSString('123'))->copyTo(fopen('php://memory', 'r'));
  }
}
