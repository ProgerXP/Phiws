<?php namespace Phiws;

abstract class Logger {
  protected static $levels = ['info', 'warn', 'error'];
  protected static $defaultMinLevel = 1;

  // If null uses $defaultMinLevel, else is an integer.
  protected $minLevel;

  // Null or string with '$$'.
  protected $echoMode;

  // 'spl_hash' => index.
  protected $seenExceptions = [];

  static function defaultMinLevel($level = null) {
    if (func_num_args()) {
      static::$defaultMinLevel = static::levelScore($level, null);
    }

    return static::$defaultMinLevel;
  }

  static function levelName($score) {
    return static::$levels[static::levelScore($score)];
  }

  static function levelScore($value, $default = 0) {
    if (is_int($value)) {
      if (isset(static::$levels[$value])) {
        return $value;
      }
    } else {
      $score = array_search($value, static::$levels, true);
      if ($score !== false) {
        return $score;
      }
    }

    isset($default) or CodeException::fail("levelScore($level): invalid level");
    return $default;
  }

  function minLevel($value = 0) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), function ($v) {
      return $this->levelScore($v, null);
    });
  }

  function echoMode($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), function ($value) {
      return $value === true ? "$$\n" : $value;
    });
  }

  function logs($level = 0) {
    $score = $this->levelScore($level);
    $minScore = isset($this->minLevel) ? $this->minLevel : static::$defaultMinLevel;
    if ($score >= $minScore) { return true; }
  }

  function logEvent($event, $args, $srcID = null) {
    if (!$this->logs()) { return; }

    $argStrs = array_map([$this, 'formatValue'], $args);

    $entry = new LogEntry([
      'message' => "$event (".join(', ', $argStrs).")",
      'sourceID' => $srcID,
    ]);

    $this->log($entry);

    return function ($resArgs) use ($event, $argStrs, $entry) {
      $resStrs = [];
      $changed = false;

      foreach ($resArgs as $i => $arg) {
        $str = $this->formatValue($arg);

        if (count($argStrs) <= $i or $argStrs[$i] !== $str) {
          $changed = true;
          $resStrs[] = $str;
        } else {
          $resStrs[] = '^';
        }
      }

      if ($changed) {
        $entry->message = str_repeat(' ', strlen($event) - 1)."= (".join(', ', $resStrs).")";
        $this->log($entry);
      }
    };
  }

  function log($msg, object $e = null, $level = 0, $srcID = null) {
    if (!($msg instanceof LogEntry)) {
      $msg = new LogEntry([
        'message'   => rtrim($msg),
        'exception' => $e,
        'level'     => $level,
        'sourceID'  => $srcID,
      ]);
    }

    $msg->level = $this->levelScore($msg->level);

    if ($this->logs($msg->level)) {
      if ($this->echoMode) {
        echo str_replace('$$', $this->format($msg, false), $this->echoMode);
      }

      $this->doLog($msg);
    }

    return $this;
  }

  abstract protected function doLog(LogEntry $entry);

  function format(LogEntry $e, $fullExceptions = true) {
    $res = [];
    $ln = "\n";

    $rewrap = function ($str, $indent = 1) use ($ln) {
      return str_replace("\n", $ln.str_repeat('  ', $indent), trim($str));
    };

    $message = $e->message;
    isset($e->sourceID) and $message = "$e->sourceID $message";

    $res[] = strtoupper(substr(static::levelName($e->level), 0, 4))." ".
             date('H:i:s', $e->time).":  ".
             $rewrap(wordwrap($message, 90, "\n", true));

    $e->exception and $res[] = '';
    $exception = $e->exception;

    while ($exception) {
      $hash = spl_object_hash($exception);

      if ($fullExceptions) {
        $res[] = "  [E#] $hash";
      } else {
        $ref = &$this->seenExceptions[$hash];
        $new = !$ref;
        $new and $ref = count($this->seenExceptions);

        $res[] = sprintf("  [E#%03d] %s", $ref, $hash);
      }

      if ($fullExceptions or $new) {
        $res[] = "  ".get_class($exception).":";
        $res[] = "    ".$rewrap(wordwrap($exception->getMessage(), 85, "\n", true), 2);
        $res[] = "  {$exception->getFile()}:{$exception->getLine()}";
        $res[] = "  ".$rewrap($exception->getTraceAsString());
      }

      $res[] = '';
      $exception = $exception->getPrevious();
    }

    return join($ln, $res);
  }

  function formatValue($value) {
    if (is_string($value)) {
      $max = 30;

      $hex = function ($str) use ($max) {
        return ltrim($str, ' ..~') === '' ? "\"$str\"" : '0x'.bin2hex(substr($str, 0, $max / 2));
      };

      if (strlen($value) > $max + 10) {
        return $hex(substr($value, 0, $max)).'...['.strlen($value).']';
      } else {
        return $hex($value);
      }
    } elseif (is_scalar($value)) {
      return var_export($value, true);
    } elseif (is_object($value)) {
      list($head, $short) = explode('Phiws\\', get_class($value), 2);
      return $head === '' ? $short : $class;
    } elseif (is_array($value)) {
      return 'array['.count($value).']';
    } elseif (is_resource($value)) {
      return 'resource['.get_resource_type($value).']';
    } else {
      return gettype($value);
    }
  }
}
