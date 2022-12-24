<?php namespace Phiws\Exceptions;

// Thrown when a stream operation has failed (e.g .unable to send or read data from
// a stream), often due to remote side going away.
//
// XXX fwrite() may not be able to write the entire buffer and may return number lower
// than its length (verified to happen on Windows when fwrite() buffer is longer than
// 65536 bytes). This likely needs to be fixed but to do so we need to add buffers and
// retransmissions across a lot of places, as well as figure if fwrite() of 0 means an
// error or not (a comment in PHP docs suggests that 0, not false is returned on any problem
// with the stream). See HeroWO's api.php's WatchdogSseClient for what it may look like.
class EStream extends \Phiws\StatusCodes\GoingAway {
  static function __callStatic($name, $args) {
    array_unshift($args, 'integer', $name);
    return call_user_func_array([get_called_class(), 'callType'], $args);
  }

  static function callType($type, $func, $arg_1 = null) {
    return static::call(array_slice(func_get_args(), 1), function ($res) use ($type) {
      return gettype($res) === $type;
    });
  }

  static function callValue($result, $func, $arg_1 = null) {
    return static::call(array_slice(func_get_args(), 1), function ($res) use ($result) {
      return $res === $result;
    });
  }

  static protected function call(array $args, $checker) {
    $ex = null;
    $func = array_shift($args);

    try {
      $res = call_user_func_array($func, $args);
    } catch (\Throwable $e) {
      $ex = $e;
    } catch (\Exception $e) {
      $ex = $e;
    }

    if (isset($ex) or !$checker($res)) {
      $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 7);

      while ($trace) {
        $ref = &$trace[0]['class'];
        if ($ref !== __CLASS__) { break; }
        array_shift($trace);
      }

      is_string($func) or $func = gettype($func);
      static::fail("{$trace[0]['function']}: $func() error", null, $ex);
    }

    return $res;
  }

  /**
   * Wrappers around frequently-used PHP functions
   */

  static function fopen($path, $mode) {
    return static::callType('resource', __FUNCTION__, $path, $mode);
  }

  static function fread($handle, $length) {
    if (PHP_MAJOR_VERSION >= 8) {
      // fread() in PHP 8 started to return false instead of empty string on an
      // alive connection when there's no data available. Doesn't seem possible
      // to detect error condition in this case.
      $func = function () {
        $res = fread(...func_get_args());
        return $res === false ? '' : $res;
      };
    } else {
      $func = __FUNCTION__;
    }
    return static::callType('string', $func, $handle, $length);
  }

  static function fseek($handle, $offset, $pos = SEEK_START) {
    return static::callValue(0, __FUNCTION__, $handle, $offset, $pos);
  }

  static function fstat($handle) {
    return static::callType('array', __FUNCTION__, $handle);
  }

  static function ftruncate($handle, $size = 0) {
    return static::callValue(true, __FUNCTION__, $handle, $size);
  }

  static function rewind($handle) {
    return static::callValue(true, __FUNCTION__, $handle);
  }

  static function stream_filter_append($stream, $filter, $flags, $params = null) {
    return static::callType('resource', __FUNCTION__, $stream, $filter, $flags, $params);
  }

  function __construct($text = null, $httpCode = null, object $previous = null) {
    if ($previous) {
      $text .= ': '.$previous->getMessage();
    }

    parent::__construct($text, $httpCode, $previous);
  }
}
