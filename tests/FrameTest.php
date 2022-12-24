<?php namespace Phiws;

use Phiws\DataSources\String as DSSString;

// XXX Test class constants (e.g. Status::SWITCHING).
// XXX Ensure all accessors are defined for public properties in BaseTun, etc.
// XXX Ensure all events are listed in Plugin.

class TestFrame extends Frames\TextData {
}

class TestStreamFilter extends \php_user_filter {
  static $writes = [];

  function filter($in, $out, &$consumed, $closing) {
    while ($bucket = stream_bucket_make_writeable($in)) {
      static::$writes[] = $bucket->data;
      $consumed += $bucket->datalen;
      stream_bucket_append($out, $bucket);
    }

    return PSFS_PASS_ON;
  }
}

class FrameTests extends \PHPUnit_Framework_TestCase {
  function setUp() {
    Frame::$bufferSize = 32768;

    TestStreamFilter::$writes = [];
    stream_filter_register('TestStreamFilter', TestStreamFilter::class);
  }

  function testAccessors() {
    $time = microtime(true);
    $frame = new TestFrame;

    $this->assertNull($frame->masker());
    $this->assertNull($frame->extensionData());
    $this->assertNull($frame->applicationData());

    $frameTime = $frame->timeConstructed();
    $this->assertTrue($frameTime >= $time and $frameTime <= microtime(true));

    $this->assertTrue($frame->header() instanceof FrameHeader);
  }

  function testMasker() {
    $frame = new TestFrame;

    $masker = Maskers\Xor32::withNewKey();
    $frame->masker($masker);
    $this->assertSame($masker, $frame->masker());
    $this->assertSame($masker, $frame->masker());

    $frame->masker(null);
    $this->assertNull($frame->masker());
  }

  function testExtensionData() {
    $frame = new TestFrame;
    $this->assertNull($frame->extensionData());

    $data = new DSSString('x');
    $frame->extensionData($data);
    $this->assertSame($data, $frame->extensionData());
    $this->assertSame($data, $frame->extensionData());

    $frame->extensionData(null);
    $this->assertNull($frame->extensionData());
  }

  function testApplicationDataRO() {
    $frame = new Frames\Ping;
    $this->assertNull($frame->applicationData());

    $data = new DSSString('x');
    $frame->applicationData($data);
    $this->assertNull($frame->applicationData());
  }

  function testApplicationDataRW() {
    $frame = new TestFrame;
    $this->assertNull($frame->applicationData());

    $data = new DSSString('x');
    $frame->applicationData($data);
    $this->assertSame($data, $frame->applicationData());
    $this->assertSame($data, $frame->applicationData());

    $frame->applicationData(null);
    $this->assertNull($frame->applicationData());
  }

  function testHeaderField() {
    $header = new FrameHeader;
    $id = $header->maskerKey = uniqid();
    $frame = TestFrame::from($header);

    $this->assertSame($id, $frame->header('maskerKey'));
    $this->assertSame($id, $frame->header()->maskerKey);
  }

  function testFrameHeaderCloned() {
    $frame = new TestFrame;
    $header = $frame->header();
    $this->assertFalse($header->rsv1);

    $header->rsv1 = true;
    $this->assertTrue($header->rsv1);
    $this->assertFalse($frame->header()->rsv1);
  }

  function testFrameTimeOnClone() {
    $frame = new TestFrame;
    $oldTime = $frame->timeConstructed();

    sleep(1);

    $clone = clone $frame;
    $this->assertNotSame($oldTime, $clone->timeConstructed());
  }

  /**
   * @dataProvider giveFrameTypes
   */
  function testFrameTypes($opcode, $class) {
    $this->assertSame($class, Frame::opcodeClass($opcode));
    $this->assertSame($opcode, $class::OPCODE);

    $frame = new $class;
    $this->assertSame($opcode, $frame->header()->opcode);
  }

  function giveFrameTypes() {
    return [
      [0x00, 'Phiws\\Frames\\Continuation'],
      [0x01, 'Phiws\\Frames\\TextData'],
      [0x02, 'Phiws\\Frames\\BinaryData'],
      [0x08, 'Phiws\\Frames\\Close'],
      [0x09, 'Phiws\\Frames\\Ping'],
      [0x0A, 'Phiws\\Frames\\Pong'],
    ];
  }

  function testOpcodeClass() {
    $validOpcodes = [];

    foreach ($this->giveFrameTypes() as $type) {
      $validOpcodes[$type[0]] = true;
    }

    for ($opcode = 0; $opcode <= 0b1111; $opcode++) {
      if (!isset($validOpcodes[$opcode])) {
        $this->assertNull(Frame::opcodeClass($opcode));
      }
    }
  }

  function testMapOpcode() {
    $newClass = uniqid();
    $unusedOpcode = 0b1111;

    $this->assertNull(Frame::opcodeClass($unusedOpcode));
    Frame::mapOpcode($unusedOpcode, $newClass);
    $this->assertSame($newClass, Frame::opcodeClass($unusedOpcode));

    $newClass = uniqid();
    $usedOpcode = 0x00;

    $this->assertNotNull(Frame::opcodeClass($usedOpcode));
    Frame::mapOpcode($usedOpcode, $newClass);
    $this->assertSame($newClass, Frame::opcodeClass($usedOpcode));
  }

  function testFrom() {
    $id = uniqid();

    $header = new FrameHeader;
    $header->rsv1 = $id;

    $extData = new DSSString($id);
    $appData = new DSSString($id);

    $frame = TestFrame::from($header, Frame::MORE_PARTS, $extData, $appData);

    $this->assertNotSame($header, $frame->header());
    $this->assertSame($extData, $frame->extensionData());
    $this->assertSame($appData, $frame->applicationData());

    $this->assertFalse($frame->isComplete());
    $this->assertFalse($frame->isFirstPart());
    $this->assertFalse($frame->isLastPart());
    $this->assertTrue($frame->hasMoreParts());

    // Ensure the header given to from() was used.
    $this->assertSame($id, $frame->header()->rsv1);

    // Ensure the header was cloned and unaffected by external changes.
    $header->rsv1 = 'z';
    $this->assertSame($id, $frame->header()->rsv1);
  }

  /**
   * @dataProvider giveFromPartialOffset
   */
  function testFromPartialOffset($offset, $isComplete, $isFirst, $hasMore, $isLast) {
    $frame = TestFrame::from(new FrameHeader, $offset);

    $this->assertSame($isComplete, $frame->isComplete());
    $this->assertSame($isFirst, $frame->isFirstPart());
    $this->assertSame($hasMore, $frame->hasMoreParts());
    $this->assertSame($isLast, $frame->isLastPart());
  }

  function giveFromPartialOffset() {
    return [
      [Frame::COMPLETE, true, false, false, false],
      [Frame::FIRST_PART, false, true, false, false],
      [Frame::MORE_PARTS, false, false, true, false],
      [Frame::LAST_PART, false, false, false, true],
    ];
  }

  function testDataProcessor() {
    $frame = new TestFrame;
    $this->assertNull($frame->dataProcessor());

    $frame->dataProcessor($proc = new DataProcessors\Blackhole($frame));
    $this->assertSame($proc, $frame->dataProcessor());
    $this->assertSame($proc, $frame->dataProcessor());
  }

  function testCustom() {
    $frame = new TestFrame;
    $this->assertSame([], $frame->custom());

    $frame->custom('k', $object = new \stdClass);
    $this->assertSame($object, $frame->custom('k'));
    $this->assertSame(['k' => $object], $frame->custom());

    $frame->custom('k2', 'v2');
    $this->assertSame('v2', $frame->custom('k2'));
    $this->assertSame(['k' => $object, 'k2' => 'v2'], $frame->custom());

    $frame->custom('k', 'v1');
    $this->assertSame('v1', $frame->custom('k'));
    $this->assertSame(['k' => 'v1', 'k2' => 'v2'], $frame->custom());

    $all = ['n1' => 'v', 'n2' => 'vv'];
    $frame->custom($all);
    $this->assertSame(null, $frame->custom('k'));
    $this->assertSame('vv', $frame->custom('n2'));
    $this->assertSame($all, $frame->custom());
  }

  function testMaskerHeader() {
    $frame = new TestFrame;
    $this->assertNull($frame->masker());
    $this->assertFalse($frame->header()->mask);
    $this->assertNull($frame->header()->maskingKey);

    $masker = Maskers\Xor32::withNewKey();
    $frame->masker($masker);
    $this->assertSame($masker, $frame->masker());
    $this->assertTrue($frame->header()->mask);
    $this->assertSame(4, strlen($frame->header()->maskingKey));

    $frame->masker(null);
    $this->assertNull($frame->masker());
    $this->assertFalse($frame->header()->mask);
    $this->assertNull($frame->header()->maskingKey);
  }

  function testFragment() {
    $frame = new TestFrame;
    $frame->masker($masker = Maskers\Xor32::withNewKey());
    $frame->applicationData(new DSSString('0123456789'));
    $this->assertSame(10, $frame->payloadLength());

    /* Fragment 1 */
    $fragment = $frame->makeFragment(0, 3);
    $this->assertSame(10, $frame->payloadLength());

    // "For a text message sent as three fragments, the first fragment would have
    // an opcode of 0x1 and a FIN bit clear, the second fragment would have an
    // opcode of 0x0 and a FIN bit clear, and the third fragment would have an
    // opcode of 0x0 and a FIN bit that is set."
    $this->assertFalse($fragment->header()->fin);
    $this->assertSame(3, $fragment->payloadLength());
    $this->assertTrue($fragment instanceof $frame);
    $this->assertSame($frame::OPCODE, $fragment->header()->opcode);
    $this->assertSame($masker, $fragment->masker());

    $src = $fragment->applicationData();
    // Not a requirement but knowing it's such a source allows for simpler testing.
    $this->assertTrue($src instanceof DataSources\Stream);
    $this->assertSame('012', stream_get_contents($src->handle(), -1, 0));

    /* Fragment 2 */
    $fragment = $frame->makeFragment(2, 5);
    $this->assertSame(10, $frame->payloadLength());

    $this->assertFalse($fragment->header()->fin);
    $this->assertSame(5, $fragment->payloadLength());
    $this->assertTrue($fragment instanceof Frames\Continuation);
    $this->assertSame(0, $fragment->header()->opcode);
    $this->assertSame($masker, $fragment->masker());

    $src = $fragment->applicationData();
    $this->assertSame('23456', stream_get_contents($src->handle(), -1, 0));

    /* Fragment 3 */
    $fragment = $frame->makeFragment(4, 6);
    $this->assertSame(10, $frame->payloadLength());

    $this->assertTrue($fragment->header()->fin);
    $this->assertSame(6, $fragment->payloadLength());
    $this->assertTrue($fragment instanceof Frames\Continuation);
    $this->assertSame(0, $fragment->header()->opcode);
    $this->assertSame($masker, $fragment->masker());

    $src = $fragment->applicationData();
    $this->assertSame('456789', stream_get_contents($src->handle(), -1, 0));

    /* Fragment 4 */
    $fragment = $frame->makeFragment(9, 1);
    $this->assertTrue($fragment->header()->fin);
    $this->assertSame(1, $fragment->payloadLength());
  }

  function testFragmentAppDataOffset() {
    $frame = new TestFrame;
    $origSrc = new DSSString('0123456789');
    $frame->applicationData($origSrc);

    $this->assertSame('01', $origSrc->read(2));
    $this->assertSame('2', $origSrc->read(1));

    $fragment = $frame->makeFragment(1, 5);

    $this->assertSame('34567', $origSrc->read(5));

    $fragSrc = $fragment->applicationData();
    $this->assertSame('12345', stream_get_contents($fragSrc->handle(), -1, 0));

    $this->assertSame('89', $origSrc->read(100));
    $this->assertNull($origSrc->read(1));
  }

  function testFragmentNegativeOffset() {
    $frame = new TestFrame;
    $frame->applicationData(new DSSString('0123456789'));

    $this->expectException('Phiws\\CodeException');
    $this->expectExceptionMessage('offset');

    $frame->makeFragment(-1, 3);
  }

  function testFragmentOffsetExceeding() {
    $frame = new TestFrame;
    $frame->applicationData(new DSSString('0123456789'));

    $this->expectException('Phiws\\CodeException');
    $this->expectExceptionMessage('length');

    $frame->makeFragment(10, 1);
  }

  function testFragmentLengthExceeding() {
    $frame = new TestFrame;
    $frame->applicationData(new DSSString('0123456789'));
    $fragment = $frame->makeFragment(8, 2);

    $this->assertTrue($fragment->header()->fin);
    $this->assertSame(2, $fragment->payloadLength());

    $src = $fragment->applicationData();
    $this->assertSame('89', stream_get_contents($src->handle(), -1, 0));
  }

  function testFragmentWithExtData() {
    $frame = new TestFrame;
    $frame->applicationData(new DSSString('0123456789'));
    $frame->extensionData(new DSSString('0123456789'));

    $this->expectException('Phiws\\CodeException');
    $this->expectExceptionMessage('extension');

    $frame->makeFragment(0, 5);
  }

  /**
   * @dataProvider giveFragmentWithPartialOffset
   */
  function testFragmentWithPartialOffset($offset) {
    $src = new DSSString('0123456789');
    $frame = TestFrame::from(new FrameHeader, $offset, null, $src);

    $this->expectException('Phiws\\CodeException');
    $this->expectExceptionMessage('partial');

    $frame->makeFragment(0, 5);
  }

  function giveFragmentWithPartialOffset() {
    return [
      [Frame::FIRST_PART],
      [Frame::MORE_PARTS],
      [Frame::LAST_PART],
    ];
  }

  function testFragmentWithoutAppData() {
    $frame = new TestFrame;

    $this->expectException('Phiws\\CodeException');
    $this->expectExceptionMessage('application');

    $frame->makeFragment(8, 2);
  }

  function testPayloadLength() {
    $frame = new TestFrame;
    $this->assertSame(0, $frame->payloadLength());

    $frame->extensionData(new DSSString('012'));
    $this->assertSame(3, $frame->payloadLength());

    $frame->applicationData(new DSSString('3456789'));
    $this->assertSame(10, $frame->payloadLength());

    $frame->extensionData(null);
    $this->assertSame(7, $frame->payloadLength());

    $frame->applicationData(null);
    $this->assertSame(0, $frame->payloadLength());
  }

  // RFC, example on page 38.
  function testKnownFragmentation() {
    $src = new DSSString('Hello');
    $frame = new Frames\TextData;
    $frame->applicationData($src);

    $frag1 = $frame->makeFragment(0, 3);
    $frag2 = $frame->makeFragment(3, 2);

    $h = fopen('php://memory', 'w+b');

    $frag1->writeTo($h);
    $this->assertSame("\x01\x03\x48\x65\x6C", stream_get_contents($h, -1, 0));

    ftruncate($h, 0);

    $frag2->writeTo($h);
    $this->assertSame("\x80\x02\x6C\x6F", stream_get_contents($h, -1, 0));

    fclose($h);
  }

  // RFC, page 39.
  function testKnownMaskedPong() {
    $data = hex2bin('8A8537FA213D7F9F4D5158');

    $header = new FrameHeader;
    $offset = $header->parse($data);
    $this->assertTrue($header->mask);
    $this->assertTrue($header->fin);
    $this->assertSame($header->opcode, 0x0A);

    $class = Frame::opcodeClass($header->opcode);
    $this->assertSame(Frames\Pong::class, $class);

    $appData = substr($data, $offset);
    list(, $key) = unpack('N', $header->maskingKey);
    (new Maskers\Xor32($key))->unmask($appData);

    $appData = new DSSString($appData);
    $frame = $class::from($header, null, null, $appData);

    $this->assertSame(5, $frame->payloadLength());
    $this->assertSame('Hello', (string) $frame->applicationData());
  }

  /**
   * @dataProvider giveMultiWriteTo
   */
  function testMultiWriteTo(array $chunks, $combinedFrames, $maskEach) {
    // Total $bufferSize = 9 
    // (frame will be written to buffer if it's payload fits, header excluded)
    //
    // written to buffer (HH = frame header):
    //
    // Frame1 [4 bytes]:
    // HHF1
    // Frame2 [3]:
    // HHf
    //
    // not written because the buffer overflows:
    //
    // Frame3 [5]:
    // HHG3G
    // Frame4 [12]:
    // HHg4g4g4g4g4g4g4
    // Frame5 [0]

    Frame::$bufferSize = 9;

    $frames = [];
    $complete = $firstWrite = '';

    foreach ($chunks as $i => $str) {
      $frame = $frames[] = new TestFrame;
      $frame->extensionData(new DSSString($str));

      $mask = ($maskEach > 0 and ($i + 1) % $maskEach == 0);

      if ($mask) {
        $masker = Maskers\Xor32::withNewKey();
        $frame->masker($masker);
        $masker->mask($str);
      }

      $str = $frame->header()->build().$str;
      $complete .= $str;

      if ($i < $combinedFrames) {
        $firstWrite .= $str;
      }
    }

    $handle = fopen('php://memory', 'w+b');
    $filterHandle = $this->appendFilter($handle);

    $size = Frame::multiWriteTo($handle, $frames);

    // Warning: PHPUnit produces segfault if a test crashes with a user filter
    // (most likely due to exception trace/serialization problems).
    isset($filterHandle) and stream_filter_remove($filterHandle);

    $this->assertSame(strlen($complete), $size);
    $this->assertSame($complete, stream_get_contents($handle, -1, 0));
    $this->assertSame($firstWrite, TestStreamFilter::$writes[0]);

    if (count($chunks) === $combinedFrames) {
      $this->assertCount(1, TestStreamFilter::$writes);
    }

    fclose($handle);
  }

  function giveMultiWriteTo() {
    $f1 = 'F1';
    $f2 = 'f';
    $f3 = 'G3G';
    $f4 = 'g4g4g4g4g4';
    $f5 = '';

    $tests = [
      [[$f1], 1], 
      [[$f1, $f2], 2], 
      [[$f1, $f2, $f3], 2], 
      [[$f1, $f2, $f3, $f4], 2],
      [[$f1, $f2, $f3, $f4, $f5], 2],
    ];

    $res = [];

    foreach ($tests as $args) {
      foreach ([0, 2] as $mask) {
        $args[2] = $mask;
        $res[] = $args;
      }
    }

    return $res;
  }

  function appendFilter($handle) {
    $h = stream_filter_append($handle, 'TestStreamFilter', STREAM_FILTER_WRITE);
    $this->setBuffers($handle, 1);
    return $h;
  }

  function setBuffers($handle, $size) {
    stream_set_chunk_size($handle, $size);
    stream_set_read_buffer($handle, $size);
    stream_set_write_buffer($handle, $size);
  }

  /** 
   * @dataProvider giveWriteTo
   */
  function testWriteTo($extData, $appData, $singleWrite, $headerLength, $mask) {
    if (!$singleWrite) {
      Frame::$bufferSize = 512;
    }

    $extDataSrc = strlen($extData) ? new DSSString($extData) : null;
    $appDataSrc = strlen($appData) ? new DSSString($appData) : null;
    $frame = TestFrame::from(new FrameHeader, null, $extDataSrc, $appDataSrc);

    $masker = $mask ? Maskers\Xor32::withNewKey() : null;

    if ($masker) {
      $headerLength += 4;
      $frame->masker($masker);
    }

    $handle = fopen('php://memory', 'w+b');

    if ($singleWrite) {
      $filterHandle = $this->appendFilter($handle);
    }

    $res = $frame->writeTo($handle);
    isset($filterHandle) and stream_filter_remove($filterHandle);

    $combined = $extData.$appData;
    $masker and $masker->mask($combined);

    $this->assertSame($headerLength + strlen($combined), $res);
    $this->assertTrue($combined === stream_get_contents($handle, -1, $headerLength));

    if ($singleWrite) {
      $this->assertSame([stream_get_contents($handle, -1, 0)], TestStreamFilter::$writes);
    }
  }

  function giveWriteTo() {
    // Picking an odd length to test how partial chunks are copied.
    $rn1 = Utils::randomKey(102400 - 23);
    $rn2 = Utils::randomKey(102400);

    $tests = [
      // Small frame.
      ['',   '',   true, 2],
      ['',   '34', true, 2],
      ['12', '',   true, 2],
      ['12', '34', true, 2],

      // Big frame.
      ['',   $rn2, false, 10],
      [$rn1, '',   false, 10],
      [$rn1, $rn2, false, 10],
    ];

    $res = [];

    foreach ($tests as $args) {
      foreach ([false, true] as $mask) {
        $args[4] = $mask;
        $res[] = $args;
      }
    }

    return $res;
  }

  /**
   * @dataProvider giveWriteToMaskedEdge
   */
  function testWriteToMaskedEdge($extData, $appData) {
    // Frame's payload is encoded as a single stream of concatenated data: 
    // extension + application. It's wrong to encode the two separately because
    // standard XOR masking uses a 32-bit key. For example, mask(00 11 22) + mask(33)
    // will correctly encode first 3 bytes but 4th byte must be encoded with 4th byte
    // of the key - here, instead, it is encoded with 1st byte of the key (because it's
    // a next context). 

    Frame::$bufferSize = 65536;

    $masker = Maskers\Xor32::withNewKey();
    $extData = new DSSString($extData);
    $appData = new DSSString($appData);
    $expectedPayload = $extData.$appData;
    $masker->mask($expectedPayload);

    $frame = TestFrame::from(new FrameHeader, null, $extData, $appData);
    $frame->masker($masker);

    $handle = fopen('php://memory', 'w+b');
    $frame->writeTo($handle);

    $headerLength = 1 + count( FrameHeader::lengthToBits(strlen($expectedPayload)) ) + 4;
    $this->assertSame($headerLength + strlen($expectedPayload), ftell($handle));

    // Easy to spot test problem without comparing entire strings in the output.
    $expected = bin2hex(substr($expectedPayload, strlen($extData) - 4, 8));
    $read = stream_get_contents($handle, 8, $headerLength + strlen($extData) - 4);
    $msg = "payloadLength = ".strlen($expectedPayload);
    $this->assertSame($expected, bin2hex($read), $msg);

    $this->assertTrue($expectedPayload === stream_get_contents($handle, -1, $headerLength));
    fclose($handle);
  }

  function giveWriteToMaskedEdge() {
    $res = [
      // Smaller than $bufferSize.
      ['1234567', '89012'],
      [Utils::randomKey(7321),  Utils::randomKey(10711)],
      // Bigger.
      [Utils::randomKey(72073), Utils::randomKey(19531)],
      [Utils::randomKey(13681), Utils::randomKey(68111)],
    ];

    for ($i = 64; $i < 80; $i++) {
      for ($j = 64; $j < 80; $j++) {
        $res[] = [Utils::randomKey($i), Utils::randomKey($j)];
      }
    }

    for ($i = 65530; $i < 65540; $i++) {
      for ($j = 65530; $j < 65540; $j++) {
        $res[] = [Utils::randomKey($i), Utils::randomKey($j)];
      }
    }

    return $res;
  }
}
