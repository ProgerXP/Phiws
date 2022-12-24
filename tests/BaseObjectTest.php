<?php namespace Phiws;

class TestBO extends BaseObject {
  const ID_PREFIX = 'Tbb';

  function testMakeStreamContext() {
    $this->streamContext = null;
    return $this->makeStreamContext();
  }
}

class TestBoPlugin extends Plugin {
  public $calls = [];

  function events() {
    return ['testEvent'];
  }

  function testEvent() {
    $this->calls[] = func_get_args();
  }
}

class BaseObjectTest extends \PHPUnit_Framework_TestCase {
  function testID() {
    $bo1 = new TestBO;
    $firstIndex = (int) ltrim(substr($bo1->id(), 3), 0);
    $this->assertTrue($firstIndex > 0 and $firstIndex < 9, "-$firstIndex-");
    $this->assertSame('Tbb00'.$firstIndex, $bo1->id());

    $bo2 = new TestBO;
    $this->assertSame('Tbb00'.($firstIndex + 1), $bo2->id());

    for ($i = $firstIndex + 2; $i <= $firstIndex + 10; $i++) {
      $id = "Tbb".str_pad($i, 3, '0', STR_PAD_LEFT);
      $this->assertSame($id, (new TestBO)->id());
    }
  }

  function testLogger() {
    $bo = new TestBO;
    $this->assertInstanceOf(Loggers\InMemory::class, $bo->logger());

    $logger = new Loggers\File('/dev/null');
    $bo->logger($logger);
    $this->assertSame($logger, $bo->logger());
  }

  function testPlugins() {
    $bo = new TestBO;
    $pis = $bo->plugins();

    $this->assertInstanceOf(Plugins::class, $pis);
    $this->assertSame($pis, $bo->plugins(new Plugins));
    $this->assertSame($pis, $bo->plugins());
  }

  function testStreamContextOptions() {
    $bo = new TestBO;

    $opt1 = ['http' => ['method' => 'POST']];
    $bo->streamContextOptions($opt1);
    $this->assertSame($opt1, $bo->streamContextOptions());

    $opt2 = ['ssl' => ['cafile' => '/dev/null']];
    $bo->streamContextOptions($opt2);
    $this->assertSame($opt2, $bo->streamContextOptions());

    $cx = $bo->testMakeStreamContext();
    $this->assertSame('stream-context', get_resource_type($cx));
    $this->assertSame($opt2, stream_context_get_options($cx));

    $bo->streamContextOptions([]);
    $this->assertSame([], $bo->streamContextOptions());

    $cx = $bo->testMakeStreamContext();
    $this->assertSame('stream-context', get_resource_type($cx));
    $this->assertSame([], stream_context_get_options($cx));
  }

  function testTimeout() {
    // XXX Also test fractional timeout.
    $bo = new TestBO;
    $timeout = mt_rand(11, 99);

    $this->assertSame(5, $bo->timeout());
    $bo->timeout($timeout);
    $this->assertSame($timeout, $bo->timeout());
  }

  function testLog() {
    $bo = new TestBO;
    $log = $bo->logger();

    $msg = "test [".uniqid()."]";
    $e = new \Exception;
    $level = $log::WARN;

    $this->assertCount(0, $log);
    $bo->log($msg, $e, $level);

    $this->assertCount(1, $log);

    list($entry) = $log->messages();
    $this->assertSame($msg, $entry->message);
    $this->assertSame($e, $entry->exception);
    $this->assertSame($level, $entry->level);
    $this->assertSame($bo->id(), $entry->sourceID);
  }

  function testFire() {
    $bo = new TestBO;
    $pi = new TestBoPlugin;
    $bo->plugins()->add($pi);

    $this->assertCount(1, $bo->plugins());
    $this->assertCount(0, $pi->calls);

    $a1 = uniqid();
    $a2 = [[new \stdClass]];
    $bo->fire('testEvent', [$a1, $a2]);

    $this->assertCount(1, $pi->calls);
    $this->assertSame([[ $bo, $a1, $a2 ]], $pi->calls);
  }
}
