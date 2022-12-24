<?php namespace Phiws\Plugins;

use Phiws\Frame;

// Use BufferAndTrigger for textual data of or below 1 MiB, use Blackhole for others
// (actually Blackhole is implied if no others match so it's redundant here):
//
//   $tun->plugins()->add((new DataProcessorPicker)
//      ->proc(DataProcessors\BufferAndTrigger::class)
//        ->whenIsText()
//        ->whenPayloadLength(0, 1024 * 1024)
//      ->proc(DataProcessors\Blackhole::class)
//   );
class DataProcessorPicker extends \Phiws\Plugin {
  // Array of 'DP\Class' => DPPCriteria.
  protected $classes = [];

  // Processors are evaluated in order of definition.
  function proc($class) {
    $ref = &$this->classes[$class];
    $ref or $ref = new DPPCriteria($class);
    return $ref;
  }

  // When proc(X)...proc(Y)...orProc(X), evaluation order is the same - X, Y, X.
  // proc(X)...proc(Y)...orProc(X)...proc(X) will return first proc(X), not orProc().
  function orProc($class) {
    $key = $class;

    do {
      $ref = &$this->classes[$key];
      $key .= ' ';
    } while ($ref);

    return $ref = new DPPCriteria($class);
  }

  function events() {
    return ['pickProcessorFor'];
  }

  function pickProcessorFor(\Phiws\BaseTunnel $cx, &$res, \Phiws\DataFrame $frame) {
    foreach ($this->classes as $crit) {
      if ($crit->match($frame)) {
        $class = $crit->procClass();
        $res = new $class($frame, $cx);
        return false;
      }
    }
  }
}

class DPPCriteria {
  protected $class;
  protected $criteria = [];

  function __construct($class) {
    $this->class = $class;
  }

  function procClass() {
    return $this->class;
  }

  // $max can be -1 to not check that boundary.
  function whenPayloadLength($min, $max = -1) {
    $this->criteria[__FUNCTION__] = new DPPPayloadLength($min, $max);
    return $this;
  }

  function whenIsText() {
    $this->criteria['dataType'] = new DPPDataType(true);
    return $this;
  }

  function whenIsBinary() {
    $this->criteria['dataType'] = new DPPDataType(false);
    return $this;
  }

  function match(Frame $frame) {
    foreach ($this->criteria as $crit) {
      if (!$crit->match($frame)) { return; }
    }

    return $this->class;
  }
}

abstract class DPPCriterion {
  abstract function match(Frame $frame);
}

class DPPPayloadLength extends DPPCriterion {
  protected $min;
  protected $max;

  function __construct($min, $max = -1) {
    $this->min = $min;
    $this->max = $max;
  }

  function match(Frame $frame) {
    $length = $frame->header()->payloadLength;
    return $length >= $this->min and ($this->max < 0 or $length <= $this->max);
  }
}

class DPPDataType extends DPPCriterion {
  protected $isText;

  function __construct($isText) {
    $this->isText = $isText;
  }

  function match(Frame $frame) {
    return $this->isText === ($frame instanceof \Phiws\Frames\TextData);
  }
}
