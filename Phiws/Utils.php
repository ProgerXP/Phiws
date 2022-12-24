<?php namespace Phiws;

abstract class Utils {
  // Values: integer (write to disk after this size), false (use memory for any size).
  static $tempStreamLimit = 4121440;

  static function newTempStream() {
    if (static::$tempStreamLimit === false) {
      return fopen('php://memory', 'w+b');
    } else {
      $size = (int) static::$tempStreamLimit;
      return fopen("php://temp/maxmemory:$size", 'w+b');
    }
  }

  static function fcloseAndNull(&$handle) {
    if ($handle) {
      try {
        fclose($handle);
      } catch (\Throwable $e) {
      } catch (\Exception $e) { }

      $handle = null;
    }
  }

  static function randomKey($length, $mech = null) {
    $length = (int) $length;
    $length < 1 and CodeException::fail("randomKey: zero length");

    if (($mech === null or $mech === 'php') and
        function_exists('random_bytes')) {
      return random_bytes($length);
    } elseif (($mech === null or $mech === 'openssl') and
              function_exists('openssl_random_pseudo_bytes')) {
      return openssl_random_pseudo_bytes($length);
    } elseif (($mech === null or $mech === 'dev') and
              $h = fopen('/dev/urandom', 'rb')) {
      $res = fread($h, $length);
      fclose($h);

      if (strlen($res) === $length) {
        return $res;
      }
    }

    static $seeded;

    if (!$seeded) {
      $seeded = true;
      for ($i = 0; $i < 2048; $i++) { mt_rand(); }
    }

    $res = '';

    while (strlen($res) < $length) {
      $res .= chr(mt_rand(0, 255));
    }

    return $res;
  }

  static function accessor($object, &$value, array $args, $norm = null) {
    if (count($args)) {
      list($value) = $args;

      switch ($norm) {
      case 'string':
        $value = (string) $value;
        break;
      case 'int':
        $value = (int) $value;
        break;
      case 'float':
        $value = (float) $value;
        break;
      case 'bool':
        $value = (bool) $value;
        break;
      case 'array':
        if (!is_array($value)) {
          CodeException::fail("accessor: array expected");
        }
        break;
      default:
        $norm and $value = $norm($value);
      }

      return $object;
    } else {
      return $value;
    }
  }

  static function cloneReader($value) {
    return $value ? clone $value : null;
  }

  static function dump($binStr) {
    return rtrim(chunk_split(bin2hex($binStr), 2, ' '));
  }
}
