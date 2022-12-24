<?php namespace Phiws;

use Phiws\Frames\TextData;
use Phiws\DataSources\String as DSSString;

class PipeExtension extends Extension {
  const ID = 'pext';

  public $procMethod;
  public $inPipe;
  public $inFrames;

  function sendProcessor(array $frames, Pipeline $pipe) {
    return $this->testProcessor(__FUNCTION__, 2, $frames, $pipe);
  }

  function receiveProcessor(array $frames, Pipeline $pipe) {
    return $this->testProcessor(__FUNCTION__, 3, $frames, $pipe);
  }

  protected function testProcessor($callee, $chunkLength, $frames, $pipe) {
    $this->procMethod = $callee;
    $this->inPipe = $pipe;
    $this->inFrames = $frames;

    $payload = '';

    foreach ($frames as $frame) {
      $payload .= $frame->extensionData().$frame->applicationData();
    }

    $outFrames = [];

    foreach (str_split($payload, $chunkLength) as $chunk) {
      $outFrames[] = (new TextData)->applicationData(new DSSString($chunk));
    }

    return function ($funcPipe) use (&$outFrames, $pipe) {
      return array_splice($outFrames, 0, 2);
    };
  }
}

class PipeSendExtension extends Extension {
  const ID = 'psext';

  public $index;

  function id() {
    return static::ID.$this->index;
  }

  function sendProcessor(array $frames, Pipeline $pipe) {
    $output = [];

    foreach ($frames as $frame) {
      foreach ([1, 2] as $index) {
        $data = $frame->applicationData().$this->index.$index;
        $output[] = (new TextData)->applicationData(new DSSString($data));
      }
    }

    return function () use (&$output) {
      if ($frame = array_shift($output)) {
        return [$frame];
      }
    };
  }
}

class PipeReceiveExtension extends PipeSendExtension {
  const ID = 'prext';

  function sendProcessor(array $frames, Pipeline $pipe) {
    return null;
  }

  function receiveProcessor(array $frames, Pipeline $pipe) {
    return parent::sendProcessor($frames, $pipe);
  }
}

class PipelineTest extends \PHPUnit_Framework_TestCase {
  protected $exts;
  protected $ext;
  protected $terminator;
  protected $terminated;

  function setUp() {
    $this->terminated = [];

    $this->terminator = function (array $frames, Pipeline $pipe) {
      $this->terminated[] = [$frames, $pipe];
    };
  }

  function setUpPipe($types) {
    $this->exts = new Extensions;
    $A = ord('A');

    foreach (str_split($types) as $type) {
      if ($type === 'p') {
        $this->exts->add($this->ext = new PipeExtension);
      } elseif ($type === 's' or $type === 'r') {
        $ext = $type === 's' ? new PipeSendExtension : new PipeReceiveExtension;
        $ext->index = $type.chr($A++);
        $this->exts->add($ext);
      } else {
        $this->fail();
      }
    }

    $bag = new Headers\Bag;
    $this->exts->clientBuildHeaders(new Client, $bag);
    $this->exts->clientCheckHeaders(new Client, $bag);

    $this->assertCount(strlen($types), $this->exts->active());
  }

  function testShiftID() {
    $pipe = new Pipeline;

    $pipe->ids = ['i1', 'i2', 'i3'];
    $pipe->forward = true;
    $this->assertSame('i1', $pipe->shiftID());
    $this->assertSame(['i2', 'i3'], $pipe->ids);
    $this->assertSame('i2', $pipe->shiftID());
    $this->assertSame(['i3'], $pipe->ids);
    $this->assertSame('i3', $pipe->shiftID());
    $this->assertSame([], $pipe->ids);
    $this->assertSame(null, $pipe->shiftID());
    $this->assertSame([], $pipe->ids);

    $pipe->ids = ['i1', 'i2', 'i3'];
    $pipe->forward = false;
    $this->assertSame('i3', $pipe->shiftID());
    $this->assertSame(['i1', 'i2'], $pipe->ids);
    $this->assertSame('i2', $pipe->shiftID());
    $this->assertSame(['i1'], $pipe->ids);
    $this->assertSame('i1', $pipe->shiftID());
    $this->assertSame([], $pipe->ids);
    $this->assertSame(null, $pipe->shiftID());
    $this->assertSame([], $pipe->ids);
  }

  function testLog() {
    $pipe = new Pipeline;
    $pipe->log('no context, ok', null, 'warn');

    $pipe->logger = $cx = new Client;
    $this->assertCount(0, $cx->logger());

    $pipe->log('msg', null, 'error');
    $this->assertCount(1, $cx->logger());
  }

  function testLogFrames() {
    Logger::$defaultMinLevel = 'info';

    $pipe = new Pipeline;
    $pipe->logger = $cx = new Client;
    $initCount = count($cx->logger());

    $pipe->frames = [new TextData, new Frames\BinaryData];
    $pipe->logFrames('x');
    $this->assertCount($initCount + 2, $cx->logger());

    $pipe->frames[] = new Frames\Close;
    $pipe->logFrames('x');
    $this->assertCount($initCount + 5, $cx->logger());
  }

  function testSend() {
    // Input: 
    // Frame1 = 'F1F'
    // Frame2 = 'f2f'
    //
    // Output:
    // Frame1 = 'F1'
    // Frame2 = 'Ff'
    // Frame3 = '2f

    $this->setUpPipe('p');

    $frame1 = (new TextData)
      ->extensionData(new DSSString('F1'))
      ->applicationData(new DSSString('F'));

    $frame2 = (new TextData)
      ->extensionData(new DSSString('f2f'));

    $this->exts->send([$frame1, $frame2], $this->terminator);

    $this->assertSame('sendProcessor', $this->ext->procMethod);
    $this->assertSame([$frame1, $frame2], $this->ext->inFrames);

    // PipeExtension returns 2 frames in one batch; 3 out frames total = 2 batches.
    $this->assertCount(2, $this->terminated);

    list($frames1) = $this->terminated[0];
    list($frames2) = $this->terminated[1];

    $this->checkPipe([$frame1, $frame2], 'sendProcessor', true);

    $this->assertCount(2, $frames1);
    $this->assertCount(1, $frames2);

    $this->assertSame('F1', (string) $frames1[0]->applicationData());
    $this->assertSame('Ff', (string) $frames1[1]->applicationData());
    $this->assertSame('2f', (string) $frames2[0]->applicationData());
  }

  function checkPipe(array $frames, $method, $forward) {
    $pipe = $this->ext->inPipe;

    $this->assertSame($frames, $pipe->frames);
    $this->assertSame($this->exts, $pipe->extensions);
    $this->assertSame($this->terminator, $pipe->terminator);
    $this->assertSame($method, $pipe->method);
    $this->assertSame([], $pipe->ids);
    $this->assertSame($forward, $pipe->forward);
  }

  function testReceive() {
    // 3 frames -> 2 frames (the opposite of testSend).

    $this->setUpPipe('p');

    $frame1 = (new TextData)
      ->extensionData(new DSSString('F'))
      ->applicationData(new DSSString('1'));

    $frame2 = (new TextData)
      ->extensionData(new DSSString('Ff'));

    $frame3 = (new Frames\BinaryData)
      ->applicationData(new DSSString('2f'));

    $this->exts->receive([$frame1, $frame2, $frame3], $this->terminator);

    $this->assertSame('receiveProcessor', $this->ext->procMethod);
    $this->assertSame([$frame1, $frame2, $frame3], $this->ext->inFrames);

    $this->assertCount(1, $this->terminated);

    list($frames1) = $this->terminated[0];

    $this->checkPipe([$frame1, $frame2, $frame3], 'receiveProcessor', false);

    $this->assertCount(2, $frames1);

    $this->assertSame('F1F', (string) $frames1[0]->applicationData());
    $this->assertSame('f2f', (string) $frames1[1]->applicationData());
  }

  function testNoFrames() {
    $this->setUpPipe('p');

    $this->exts->send([], $this->terminator);
    $this->exts->receive([], $this->terminator);

    $this->assertCount(0, $this->terminated);
    $this->assertNull($this->ext->procMethod);
  }

  function testNoExtensions() {
    $exts = new Extensions;
    $exts->add($ext = new PipeExtension);
    $this->assertCount(0, $exts->activeIDs());

    $frame1 = (new TextData)->applicationData(new DSSString($id1 = uniqid()));
    $frame2 = (new TextData)->applicationData(new DSSString($id2 = uniqid()));
    $exts->send([$frame1, $frame2], $this->terminator);

    $this->assertCount(1, $this->terminated);

    $frame3 = (new TextData)->applicationData(new DSSString($id3 = uniqid()));
    $frame4 = (new TextData)->applicationData(new DSSString($id4 = uniqid()));
    $exts->receive([$frame3, $frame4], $this->terminator);

    $this->assertCount(2, $this->terminated);

    list($frames1) = $this->terminated[0];
    list($frames2) = $this->terminated[1];

    $this->assertCount(2, $frames1);
    $this->assertCount(2, $frames2);

    $this->assertSame($id1, (string) $frames1[0]->applicationData());
    $this->assertSame($id2, (string) $frames1[1]->applicationData());
    $this->assertSame($id3, (string) $frames2[0]->applicationData());
    $this->assertSame($id4, (string) $frames2[1]->applicationData());
  }

  function testSendMultiple() {
    // Input: 
    // Frame1 = 'F1'
    // Frame2 = 'f2'
    //
    // Output: each 's' multiplies input by 2; 'r' passes through
    //
    // Frame1 -> 's' #1 -> 'F1sA1' -> 's' #2 -> 'F1sA1sC1' 
    //                                       -> 'F1sA1sC2' 
    //                  -> 'F1sA2' -> 's' #2 -> 'F1sA2sC1'
    //                                       -> 'F1sA2sC2'
    // Frame2 -> ... -> 4 frames: 'f2sA#sC#'

    $this->setUpPipe('srs');

    $frame1 = (new TextData)->applicationData(new DSSString('F1'));
    $frame2 = (new TextData)->applicationData(new DSSString('f2'));
    $this->exts->send([$frame1, $frame2], $this->terminator);

    $this->assertCount(8, $this->terminated);

    $expected = [
      'F1sA1sC1', 'F1sA1sC2', 'F1sA2sC1', 'F1sA2sC2', 
      'f2sA1sC1', 'f2sA1sC2', 'f2sA2sC1', 'f2sA2sC2', 
    ];

    $this->assertTerminatedFrames($expected);
  }

  function assertTerminatedFrames(array $expected, $termCount = 1) {
    $actual = [];

    foreach ($this->terminated as $term) {
      list($frames) = $term;
      $this->assertCount($termCount, $frames);

      foreach ($frames as $frame) {
        $actual[] = (string) $frame->applicationData();
      }
    }

    $this->assertSame(join("\n", $expected), join("\n", $actual));
  }

  function testReceiveMultiple() {
    // Note reverse order of processing!
    //
    // Frame1 -> ... -> 4 frames: 'F1rC#rA#'
    // Frame2 -> ... -> 4 frames: 'f2rC#rA#'

    $this->setUpPipe('rsr');

    $frame1 = (new TextData)->applicationData(new DSSString('F1'));
    $frame2 = (new TextData)->applicationData(new DSSString('f2'));
    $this->exts->receive([$frame1, $frame2], $this->terminator);

    $this->assertCount(8, $this->terminated);

    $expected = [
      'F1rC1rA1', 'F1rC1rA2', 'F1rC2rA1', 'F1rC2rA2', 
      'f2rC1rA1', 'f2rC1rA2', 'f2rC2rA1', 'f2rC2rA2', 
    ];

    $this->assertTerminatedFrames($expected);
  }
}
