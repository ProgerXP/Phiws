<?php namespace Phiws;

use Phiws\DataSources\String as DSString;
use Phiws\Frames\TextData;

class BufferAndTrigger extends DataProcessors\BufferAndTrigger {
  public $extHandle;
  public $appHandle;
  public $fired = [];

  protected function fireTrigger(DataSource $appData = null, DataSource $extData = null) {
    $this->fired[] = compact('appData', 'extData');
    return parent::fireTrigger();
  }
}

class DataProcessors_BufferAndTriggerTest extends \PHPUnit_Framework_TestCase {
  function testInheritance() {
    $ref = new \ReflectionClass(DataProcessor::class);
    $this->assertTrue($ref->isAbstract());

    $this->assertTrue( is_subclass_of(BufferAndTrigger::class, DataProcessor::class) );
    $this->assertTrue( is_subclass_of(DataProcessors\Blackhole::class, DataProcessor::class) );
    $this->assertTrue( is_subclass_of(DataProcessors\StreamCopy::class, DataProcessor::class) );
  }

  /**
   * @dataProvider giveClose
   */
  function testClose($close) {
    $header = new FrameHeader;
    $header->fin = false;
    $frame = TextData::from($header);

    $id1 = uniqid();
    $frame->applicationData($appData = new DSString($id1));
    $id2 = uniqid('foo', true);
    $frame->extensionData($extData = new DSString($id2));

    $bat = new BufferAndTrigger($frame);

    $this->assertCount(0, $bat->fired);
    $this->assertTrue(is_resource($appHandle = $bat->appHandle));
    $this->assertTrue(is_resource($extHandle = $bat->extHandle));

    $this->assertSame($id1, stream_get_contents($appHandle, -1, 0));
    $this->assertSame($id2, stream_get_contents($extHandle, -1, 0));

    $close ? $bat->close() : $bat = null;

    $this->assertFalse(is_resource($appHandle));
    $this->assertFalse(is_resource($extHandle));
  }

  function giveClose() {
    return [[true], [false]];
  }

  function testHeaderClone() {
    $header = new FrameHeader;
    $header->rsv1 = $id = uniqid();

    $bat = new BufferAndTrigger(TextData::from($header));
    $batHeader = $bat->header();
    $this->assertNotSame($header, $batHeader);
    $this->assertSame($id, $header->rsv1);
    $this->assertSame($id, $batHeader->rsv1);

    $batHeader->rsv1 = uniqid();
    $this->assertNotSame($id, $batHeader->rsv1);
    $this->assertSame($id, $header->rsv1);
    $this->assertSame($id, $bat->header()->rsv1);
  }

  function testTunnel() {
    $tunnel = new Client;
    $this->assertCount(0, $tunnel->logger());

    $bat = new BufferAndTrigger(new TextData, $tunnel);
    $this->assertSame($tunnel, $bat->tunnel());

    $bat->log(uniqid(), null, 'warn');
    $this->assertCount(1, $tunnel->logger());

    $bat = new BufferAndTrigger(new TextData, null);
    $this->assertNull($bat->tunnel());

    // Ignored for null tunnel.
    $bat->log(uniqid(), null, 'warn');
    $this->assertCount(1, $tunnel->logger());

    $bat = new BufferAndTrigger(new TextData);
    $this->assertNull($bat->tunnel());

    $bat->log(uniqid(), null, 'warn');
    $this->assertCount(1, $tunnel->logger());
  }

  function testConstructorContFrame() {
    $frame = new Frames\Continuation;

    $this->expectException('Phiws\\CodeException');
    $this->expectExceptionMessage('Continuation');

    new BufferAndTrigger($frame);
  }

  /**
   * @dataProvider giveConstructorNotFirstPartialOffset
   */
  function testConstructorNotFirstPartialOffset($offset) {
    $frame = TextData::from(new FrameHeader, $offset);

    $this->expectException('Phiws\\CodeException');
    $this->expectExceptionMessage('first');

    new BufferAndTrigger($frame);
  }

  function giveConstructorNotFirstPartialOffset() {
    return [[Frame::MORE_PARTS], [Frame::LAST_PART]];
  }

  function testConstructorComplete() {
    $header = new FrameHeader;
    $header->fin = true;

    $frame = TextData::from($header);

    $id = uniqid();
    $frame->applicationData($src = new DSString($id));

    $bat = new BufferAndTrigger($frame);
    $this->assertCount(1, $bat->fired);
    $this->assertNull($bat->extHandle);
    $this->assertNull($bat->appHandle);

    $fired = $bat->fired[0];
    $this->assertSame($src, $fired['appData']);
    $this->assertSame($id, (string) $src);
    $this->assertNull($fired['extData']);
  }

  function testConstructorFragmented() {
    $frame = new TextData;

    $id = '3c b5 77db78250e6b';
    $frame->applicationData($src = new DSString($id));

    /* 1/3 */
    $first = $frame->makeFragment(0, 3);
    $this->assertInstanceOf(get_class($frame), $first);
    $this->assertFalse($first->header()->fin);
    // isComplete() indicates is a frame's payload was completely read, and
    // fragmented frames are still whole frames so it's different from fragmentation.
    $this->assertTrue($first->isComplete());
    $this->assertSame(3, $first->payloadLength());

    $bat = new BufferAndTrigger($first);
    $this->assertCount(0, $bat->fired);
    $this->assertNull($bat->extHandle);
    $this->assertTrue(is_resource($bat->appHandle));
    $this->assertSame(3, $bat->payloadLength());

    $callbackFired = 0;
    $callback = function () use (&$callbackFired) { $callbackFired++; };

    $this->assertNull($bat->finCallback());
    $bat->finCallback($callback);
    $this->assertSame($callback, $bat->finCallback());

    /* 2/3 */
    $middle = $frame->makeFragment(3, 3);
    $this->assertInstanceOf(Frames\Continuation::class, $middle);
    $this->assertFalse($middle->header()->fin);
    $this->assertTrue($first->isComplete());
    $this->assertSame(3, $middle->payloadLength());

    $bat->append($middle);
    $this->assertCount(0, $bat->fired);

    /* 3/3 */
    $last = $frame->makeFragment(6, 12);
    $this->assertInstanceOf(Frames\Continuation::class, $last);
    $this->assertTrue($last->header()->fin);
    $this->assertTrue($first->isComplete());
    $this->assertSame(12, $last->payloadLength());

    $bat->append($last);

    $this->assertSame(1, $callbackFired);
    $this->assertCount(1, $bat->fired);

    $this->assertNull($bat->extHandle);
    $this->assertNull($bat->appHandle);

    $fired = $bat->fired[0];
    $this->assertNull($fired['extData']);

    $src = $fired['appData'];
    // For testing convenience. In reality it can be any DataSource.
    $this->assertInstanceOf(DataSources\Stream::class, $src);
    $this->assertSame(strlen($id), $src->remainingLength());
    $this->assertTrue($src->autoClose());

    $src->rewind();
    $this->assertSame($id, $src->read(100));
  }

  function testConstructorPartialOffset() {
    $header = new FrameHeader;
    $header->fin = false;

    // Simulating 2 frames on the wire representing two fragments of a single
    // frame. First fragment is read in 3 turns, second in 2. First fragment has
    // no FIN, last has FIN.
    //
    //   [FRA][ME][1_][FRAM][E2]
    //   ^-- partial offset 0
    //        ^-- partial offset 3
    //            ^-- partial offset 5 [complete], no FIN
    //               ^-- partial offset 0
    //                     ^-- partial offset 4 [complete], FIN

    $completeData = '17_cc_-d3d_45';
    $frame = new TextData;
    $frame->applicationData(new DSString($completeData));

    $frag1 = $frame->makeFragment(0, 7);
    $frag2 = $frame->makeFragment(7, 6);
    $this->assertTrue($frag1->isComplete());
    $this->assertTrue($frag2->isComplete());

    $src = $frag1->applicationData();
    $this->assertSame('17_cc_-', $src->read(100));
    $src->rewind();

    $src = $frag2->applicationData();
    $this->assertSame('d3d_45', $src->read(100));
    $src->rewind();

    /* Frag 1/2, part 1/3 */
    $frag1 = $frag1::from($frag1->header(), $frag1::FIRST_PART, null, new DSString('17_'));
    $this->assertFalse($frag1->isComplete());
    $this->assertTrue($frag1->isFirstPart());
    $this->assertFalse($frag1->hasMoreParts());
    $this->assertFalse($frag1->isLastPart());

    $bat = new BufferAndTrigger($frag1);
    $this->assertCount(0, $bat->fired);

    /* Frag 1/2, part 2/3 */
    $frag1 = $frag1::from($frag1->header(), $frag1::MORE_PARTS, null, new DSString('cc_'));
    $this->assertFalse($frag1->isComplete());
    $this->assertFalse($frag1->isFirstPart());
    $this->assertTrue($frag1->hasMoreParts());
    $this->assertFalse($frag1->isLastPart());

    $bat->append($frag1);
    $this->assertCount(0, $bat->fired);

    /* Frag 1/2, part 3/3 */
    $frag1 = $frag1::from($frag1->header(), $frag1::LAST_PART, null, new DSString('-'));
    $this->assertFalse($frag1->isComplete());
    $this->assertFalse($frag1->isFirstPart());
    $this->assertFalse($frag1->hasMoreParts());
    $this->assertTrue($frag1->isLastPart());

    $bat->append($frag1);
    $this->assertCount(0, $bat->fired);

    /* Frag 2/2, part 1/2 */
    $frag2 = $frag2::from($frag2->header(), $frag2::FIRST_PART, null, new DSString('d3d_'));
    $bat->append($frag2);
    $this->assertCount(0, $bat->fired);

    /* Frag 2/2, part 2/2 */
    $frag2 = $frag2::from($frag2->header(), $frag2::LAST_PART, null, new DSString('45'));
    $bat->append($frag2);
    $this->assertCount(1, $bat->fired);

    $this->assertNull($bat->extHandle);
    $this->assertNull($bat->appHandle);

    $fired = $bat->fired[0];
    $this->assertNull($fired['extData']);

    $src = $fired['appData'];
    $this->assertInstanceOf(DataSources\Stream::class, $src);
    $this->assertSame(strlen($completeData), $src->remainingLength());
    $this->assertTrue($src->autoClose());

    $src->rewind();
    $this->assertSame($completeData, $src->read(100));
  }

  // RFC, examples on page 38.
  function testKnownFragmentation() {
    $header = new FrameHeader;
    $bin = "\x01\x03\x48\x65\x6C";
    $offset = $header->parse($bin);
    $this->assertFalse($header->fin);
    $frag1 = TextData::from($header);
    $frag1->applicationData(new DSString(substr($bin, $offset)));

    $header = new FrameHeader;
    $bin = "\x80\x02\x6C\x6F";
    $offset = $header->parse($bin);
    $this->assertTrue($header->fin);
    $frag2 = Frames\Continuation::from($header);
    $frag2->applicationData(new DSString(substr($bin, $offset)));

    $bat = new BufferAndTrigger($frag1);
    $bat->append($frag2);
    $this->assertCount(1, $bat->fired);
    $this->assertNull($bat->fired[0]['extData']);

    $handle = $bat->fired[0]['appData']->handle();
    $this->assertSame('Hello', stream_get_contents($handle, -1, 0));
  }
}
