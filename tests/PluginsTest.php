<?php namespace Phiws;

class TestPlugins extends Plugins {
  function allHooks() {
    return $this->hooks;
  }
}

class TestPlugin extends Plugin {
  static $break = false;

  function events() {
    return ['ev1', 'ev3'];
  }

  function ev1($cx, &$res, $arg) {
    $res = [dechex($arg), $cx];
  }

  function ev2($cx) {
    throw new \Exception('ev2() called');
  }

  function ev3($cx, &$res, $arg) {
    $res .= $arg;
    return static::$break ? false : null;
  }
}

class TestInFrontPlugin extends TestPlugin {
  function events() {
    return ['-ev3'];
  }

  function ev3($cx, &$res, $arg) {
    $res .= "(infr)";
    return static::$break ? false : null;
  }
}

class PluginsTest extends \PHPUnit_Framework_TestCase {
  function setUp() {
    TestPlugin::$break = false;
  }

  function hooks() {
    $res = [];

    foreach (func_get_args() as $pi) {
      foreach (['ev1', 'ev3'] as $func) {
        $res[$func][] = [$pi, $func];
      }
    }

    return $res;
  }

  function testTestHooks() {
    $pi = new TestPlugin;
    $pi2 = new TestPlugin;

    $hooks = [
      'ev1' => [[$pi, 'ev1'], [$pi2, 'ev1']],
      'ev3' => [[$pi, 'ev3'], [$pi2, 'ev3']],
    ];

    $this->assertSame($hooks, $this->hooks($pi, $pi2));
  }

  function testInheritance() {
    $ref = new \ReflectionClass('Phiws\\Plugin');
    $this->assertTrue($ref->isAbstract());

    $pis = new TestPlugins;

    $this->assertTrue($pis instanceof \Countable);
    $this->assertTrue($pis instanceof \IteratorAggregate);
  }

  function testHas() {
    $pis = new TestPlugins;
    $pis->add($pi = new TestPlugin);
    $this->assertTrue($pis->has($pi));
    $this->assertFalse($pis->has(new TestPlugin));
  }

  function testAdd() {
    $pis = new TestPlugins;
    $this->assertSame([], $pis->allHooks());
    $this->assertSame(0, $pis->allCount());

    foreach ($pis as $item) {
      $this->fail();
    }

    $pi = new TestPlugin;
    $pis->add($pi);
    $this->assertSame(1, $pis->allCount());
    $this->assertSame(1, count($pis));

    $this->assertSame($this->hooks($pi), $pis->allHooks());

    $pi2 = new TestPlugin;
    $pis->add($pi2, true);
    $this->assertSame(2, $pis->allCount());
    $this->assertSame(1, count($pis));

    $this->assertSame($this->hooks($pi, $pi2), $pis->allHooks());

    foreach ($pis as $item) {
      $this->assertTrue(in_array($item, [$pi, $pi2], true));
    }
  }

  function testAddDuplicate() {
    $pis = new TestPlugins;
    $pis->add($pi = new TestPlugin);

    $hooks = $pis->allHooks();
    $pis->add($pi);
    $this->assertSame(1, $pis->allCount());
    $this->assertSame($hooks, $pis->allHooks());

    $pis->add($pi, true);
    $this->assertSame(1, $pis->allCount());
    $this->assertSame($hooks, $pis->allHooks());

    $pis->add($pi = new TestPlugin, true);
    $this->assertSame(2, $pis->allCount());
    $this->assertNotSame($hooks, $pis->allHooks());

    $hooks = $pis->allHooks();
    $pis->add($pi);
    $this->assertSame(2, $pis->allCount());
    $this->assertSame($hooks, $pis->allHooks());

    $pis->add(new TestPlugin);
    $this->assertSame(3, $pis->allCount());
  }

  function testClear() {
    $pis = new TestPlugins;

    $pis->add($pi = new TestPlugin);
    $pis->clear();
    $this->assertSame(0, $pis->allCount());
    $this->assertSame([], $pis->allHooks());

    $pis->clear();
    $this->assertSame(0, $pis->allCount());

    $pis->add(new TestPlugin);
    $pis->add($pi = new TestPlugin, true);
    $pis->clear();
    $this->assertSame(1, $pis->allCount());
    $this->assertSame($this->hooks($pi), $pis->allHooks());

    $pis->clear();
    $this->assertSame(1, $pis->allCount());
    $this->assertSame($this->hooks($pi), $pis->allHooks());

    $pis->add($pi2 = new TestPlugin);
    $this->assertSame(2, $pis->allCount());
    $this->assertSame($this->hooks($pi, $pi2), $pis->allHooks());
  }

  function testContext() {
    $cx = new \stdClass;
    $pis = new TestPlugins($cx);
    $this->assertSame($cx, $pis->context());

    $res = null;
    $pis->fire('ev1', [&$res, 127]);
    $this->assertNull($res);

    $pis->add(new TestPlugin);

    $res = null;
    $pis->fire('ev1', [&$res, 127]);
    $this->assertSame(['7f', $cx], $res);

    $pis->add(new TestPlugin);

    $res = null;
    $pis->fire('ev3', [&$res, 'Xo']);
    $this->assertSame('XoXo', $res);

    $res = null;
    $pis->fire('ev2', [&$res]);
    $this->assertNull($res);
  }

  function testContextNull() {
    $pis = new TestPlugins;
    $this->assertNull($pis->context());

    $pis->add(new TestPlugin);

    $res = null;
    $pis->fire('ev1', [&$res, 127]);
    $this->assertSame(['7f', null], $res);
  }

  function testFireBreak() {
    $pis = new TestPlugins;
    $pis->add(new TestPlugin);
    $pis->add(new TestPlugin);
    $pis->add(new TestPlugin);
    
    $res = null;
    $pis->fire('ev3', [&$res, 'Xo']);
    $this->assertSame('XoXoXo', $res);
    
    $res = null;
    TestPlugin::$break = true;
    $pis->fire('ev3', [&$res, 'Xo']);
    $this->assertSame('Xo', $res);
  }

  function testAddInFront() {
    $pis = new TestPlugins;
    $pis->add(new TestPlugin);
    $pis->add(new TestInFrontPlugin);
    $pis->add(new TestPlugin, true);

    $res = null;
    $pis->fire('ev3', [&$res, 'Xo']);
    $this->assertSame('(infr)XoXo', $res);
    
    $res = null;
    TestPlugin::$break = true;
    $pis->fire('ev3', [&$res, 'Xo']);
    $this->assertSame('(infr)', $res);
  }
}
