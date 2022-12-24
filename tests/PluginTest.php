<?php namespace Phiws;

use Reflection;
use ReflectionClass;
use ReflectionMethod;

if (!class_exists('Phisocks', false)) {
  eval('class Phisocks { }');
}

class PluginTest extends \PHPUnit_Framework_TestCase {
  // List of methods that are defined in Plugin but are not event handlers (used
  // in fire()) and so don't have to be included into events() of a plugin.
  static $pluginDefinedNonEvents = [
    'isGlobalHook',
    'firing',
    'addedTo',
    'removedFrom',
  ];

  static $uncheckedMethods = ['__construct', 'events'];

  /**
   * @dataProvider giveClasses
   */
  function testInheritance($class, $extraMethods) {
    $ref = new ReflectionClass("Phiws\\Plugins\\$class");

    // Plugins don't have to extend Plugin specifically, like MaxPayloadLength.
    $this->assertTrue($ref->isSubclassOf(PluginInterface::class));
  }

  /**
   * Check for typos in event method handler names.
   *
   * @dataProvider giveClasses
   */
  function testKnownPublicMethods($class, $extraMethods) {
    $ref = new ReflectionClass("Phiws\\Plugins\\$class");

    foreach ($extraMethods as $name) {
      $method = $ref->getMethod($name);
      $this->assertTrue($method->isPublic(), "$class: no known public method $name()");
    }
  }

  /**
   * Check for typos in event method handler names. Methods that are not found
   * in any of the base classes and not explicitly listed in $extraMethods.
   *
   * @dataProvider giveClasses
   */
  function testUnknownPublicMethods($class, $extraMethods) {
    $ref = new ReflectionClass("Phiws\\Plugins\\$class");
    $parentRef = $ref->getParentClass();
    $baseRef = new ReflectionClass(Plugin::class);
    $extraMethods = array_merge($extraMethods, static::$uncheckedMethods);

    foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $refMethod) {
      $name = $refMethod->getName();

      if ($refMethod->getDeclaringClass()->getName() === $ref->getName()
          and !$parentRef->hasMethod($name) and !$baseRef->hasMethod($name)
          and !in_array($name, $extraMethods)) {
        $this->fail("$class: unknown public method $name()");
      }
    }
  }

  /**
   * @dataProvider giveClasses
   */
  function testReturnedEvents($class, $extraMethods, $constructor) {
    if (!isset($constructor)) { return; }

    $ref = new ReflectionClass("Phiws\\Plugins\\$class");

    $events = $this->listEventMethodNames($ref);
    $events = array_diff($events, $extraMethods, static::$uncheckedMethods);

    if ($constructor instanceof \Closure) {
      $plugin = $constructor();
    } else {
      $plugin = $ref->newInstanceArgs($constructor);
    }

    $returnedEvents = $plugin->events();

    foreach ($returnedEvents as &$eventRef) {
      $eventRef = ltrim($eventRef, '-');
    }

    sort($events);
    sort($returnedEvents);

    $msg = "$class: list returned by events() differs from actually defined methods";
    $this->assertSame(join("\n", $events), join("\n", $returnedEvents), $msg);
  }

  function listEventMethodNames(ReflectionClass $ref) {
    $baseRef = new ReflectionClass(Plugin::class);
    $events = [];

    while ( $ref and $ref->getName() !== $baseRef->getName() ) {
      foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $refMethod) {
        if ($refMethod->getDeclaringClass()->getName() === $ref->getName()) {
          $events[] = $refMethod->getName();
        }
      }

      $ref = $ref->getParentClass();
    }

    return array_diff($events, static::$pluginDefinedNonEvents);
  }

  function giveClasses() {
    $stateful = ['parent'];

    // Indexed values:
    // 0 - class name relative to Phiws\Plugins
    // 1 - public methods that are not event handlers
    // 2 - null, arguments for constructor or a callable returning this
    //     plugin's instance
    return [
      [
        'AutoReconnect',
        array_merge($stateful, [
          'maxWait',
          'currentWait',
          'ignoreStatus',
          'waitOnStatus',
        ]),
        [],
      ],
      [
        'BlockingServer',
        array_merge($stateful, [
          'freshConnectionLengthMargin',
          'initialContentLength',
          'fallbackMul',
          'minContentLength',
          'activeContentLength',
          'onReconnect',
        ]),
        [],
      ],
      [
        'DataProcessorPicker',
        [
          'proc',
          'orProc',
        ],
        [],
      ],
//      [
//        'Cookies',
//        [],
//        null,
//      ],
      [
        'HttpBasicAuth',
        [
          'login',
          'password',
          'getEncoded',
        ],
        ['us', 'pw'],
      ],
      [
        'MaxPayloadLength',
        [
          'inboundLimit',
          'outboundLimit',
          'fragmentMode',
          'preFragment',
          'postFragment',
          'errorOnFragment',
        ],
        null,
      ],
//      [
//        'Origin',
//        [],
//        null,
//      ],
      [
        'Phisocks',
        [
          'phisocks',
        ],
        [new \Phisocks],
      ],
      [
        'RequestURI',
        array_merge($stateful, [
          'hostName',
          'baseURI',
          'redirectCode',
          'redirectURI',
          'redirectTo',
          'maxRedirects',
        ]),
        ['local', '/'],
      ],
      [
        'Statistics',
        [
          'lastLogTime',
          'logEach',
          'log',
          'formatTimeOf',
          'formatTime',
        ],
        [],
      ],
      [
        'UserAgent',
        [
          'version',
        ],
        [],
      ],
    ];
  }

  /**
   * @dataProvider giveClasses
   */
  function testMethodSignatures($class) {
    $ref = new ReflectionClass("Phiws\\Plugins\\$class");
    $baseRef = new ReflectionClass("Phiws\\Plugin");

    foreach ($ref->getMethods() as $refMethod) {
      try {
        $baseMethod = $baseRef->getMethod($refMethod->getName());
      } catch (\Exception $e) { 
        continue;
      }

      $this->assertSignature($baseMethod, $refMethod);
    }
  }

  function assertSignature(ReflectionMethod $expected, ReflectionMethod $actual) {
    $name = $actual->getName();

    $expectedMods = Reflection::getModifierNames($expected->getModifiers());
    $expectedMods = array_diff($expectedMods, ['abstract']);
    $actualMods = Reflection::getModifierNames($actual->getModifiers());
    $this->assertSame(array_values($expectedMods), array_values($actualMods), "$name: different modifiers");

    $this->assertTrue($expected->getNumberOfRequiredParameters() >= $actual->getNumberOfRequiredParameters(), "$name: different number of required parameters");

    $expectedArgs = $expected->getParameters();
    $actualArgs = $actual->getParameters();

    foreach ($actualArgs as $arg) {
    }
  }
}
