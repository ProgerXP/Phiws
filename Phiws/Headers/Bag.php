<?php namespace Phiws\Headers;

use Phiws\CodeException;
use Phiws\Exceptions\EStream;

class Bag implements \Countable, \IteratorAggregate {
  // Null or Status.
  protected $status;

  // Array of name => value where value is an array (can't specify duplicate names).
  protected $headers = [];

  static function makeParameterized($id, array $params) {
    $tokens = [];

    foreach ($params as $key => $value) {
      if ($value === true) {
        $value = '';
      } elseif (strpbrk($value, ',;=" ') or trim($value) !== (string) $value) {
        $value = '="'.addcslashes($value, '\\"').'"';
      } else {
        $value = "=$value";
      }

      $tokens[] = trim($key).$value;
    }

    array_unshift($tokens, $id);
    return join('; ', $tokens);
  }

  /** 
   * Accessors
   */

  function status(Status $value = null) {
    return \Phiws\Utils::accessor($this, $this->{__FUNCTION__}, func_get_args());
  }

  // Duplicate headers are counted as one.
  #[\ReturnTypeWillChange]
  function count() {
    return count($this->headers);
  }

  /**
   * Reading Headers
   */

  #[\ReturnTypeWillChange]
  function getIterator() {
    return new \ArrayIterator($this->headers);
  }

  // For token list headers like Connection. There can be multiple headers, one
  // with its own token, or a single header with comma-separated tokens.
  // Returned tokens won't duplicate.
  function getTokens($name, $lowerCase = false) {
    $res = [];

    foreach ($this->getAll($name) as $value) {
      $lowerCase and $value = strtolower($value);
      $res += array_flip(array_map('trim', explode(',', $value)));
    }

    unset($res['']);
    return array_keys($res);
  }

  // Of standard HTTP format: 
  //   tok1; param="value with spaces"; param2, tok2; paramoftok2=val
  //
  // $lowerCase only affects token and parameter names, not values.
  //
  // Returns array of [ ['token', [['param', 'value'], ['p2', 'v2'], ...]], ['tok2'], ... ]
  // where tokens can duplicate (see sections 9.1 of RFC 6455 and 5.2 of RFC 7692).
  function getParametrizedTokens($header, $lowerCase = false) {
    $regexp = <<<'RE'
  ([,;]|^)
  \s*
  ([\w-]+) 
  (
    \s* = \s*
    (?:
      (["']) ( (?: \\(?:\\\\)*\4|\\\\|[^\\\4])*? ) \4
    | ([^;,]*)
    )
  )?
  ()
  \s*
  (?=[,;]|$)
RE;
  
    $res = $matches = [];
    $headers = $this->getAll($header);
    $joined = join(', ', $headers);

    if ($headers and !preg_match_all("~$regexp~ux", $joined, $matches, PREG_SET_ORDER)) {
      \Phiws\StatusCodes\MalformedHttpHeader::fail("$header ($joined): malformed parametrized list");
    }

    foreach ($matches as $match) {
      list(, $type, $key, $isValue, , $quotedValue, $value) = $match;
      $type or $type = ',';

      if (strlen($quotedValue)) {
        $value = stripcslashes($quotedValue);
      } elseif (strlen($isValue)) {
        $value = trim($value);
      } else {
        // param= or param="" set value to '' (empty string).
        // Just param set value to true.
        $value = true;
      }

      if ($type === ',') {
        if ($value === true) {
          $lowerCase and $key = strtolower($key);
          $res[] = [trim($key), []];
          continue;
        } else {
          // As found in Set-Cookie: "name=value; expire=...".
          $res[] = ['', [trim($key), $value]];
        }
      } else {
        $ref = &$res[count($res) - 1][1];
        $lowerCase and $key = strtolower($key);
        $ref[] = [trim($key), $value];
      }
    }

    return $res;
  }

  function getAll($name) {
    return isset($this->headers[$name]) ? $this->headers[$name] : [];
  }

  function get($name) {
    $list = $this->getAll($name);
    return $list ? end($list) : null;
  }

  /**
   * Setting Headers
   */

  function set($name, $value) {
    return $this->add($name, $value, true);
  }

  function add($name, $value, $clear = false) {
    if (strpbrk($name.$value, "\0\r\n")) {
      \Phiws\StatusCodes\MalformedHttpHeader::fail("$name ($value): wrong symbols in name and/or value");
    }

    $ref = &$this->headers[$this->normName($name)];
    if ($clear or !$ref) { $ref = []; }
    $ref[] = (string) $value;
    return $this;
  }

  function normName($name) {
    return strtr(ucwords( strtr(strtolower(trim($name)), '_-', '  ') ), ' ', '-');
  }

  function addParametrized($header, $id, array $params) {
    $this->add($header, static::makeParameterized($id, $params));
    return $this;
  }

  function remove($name) {
    unset( $this->headers[$this->normName($name)] );
    return $this;
  }

  function clear() {
    $this->headers = [];
    return $this;
  }

  /**
   * List Parsing/Building
   */

  function parseFromStream($handle, $statusClass = null) {
    while (true) {
      // It recognizes \r\n and \n (on *nix), but not \r.
      $line = EStream::callType('string', 'fgets', $handle, 8192);

      if ($line === '') {
        EStream::fail("parseFromStream: unexpected stream end while reading headers");
      } elseif (substr($line, -2) !== "\r\n") {
        EStream::fail("parseFromStream: too long header or malformed line break");
      } elseif ($line === "\r\n") {
        // End of headers, start of data.
        return $this;
      }

      $parts = explode(':', substr($line, 0, -2), 2);

      if (count($parts) === 2) {
        list($name, $value) = $parts;
        $this->add($name, ltrim($value));
      } elseif (!$statusClass) {
        // Only first header can be a status line.
        \Phiws\StatusCodes\MalformedHttpHeader::fail("$line: no value-separating colon");
      } else {
        $this->status($statusClass::from($line));
        $statusClass = null;
      }
    }
  }

  // Includes 1 trailing pair of "\r\n".
  function join($status = true) {
    $headers = $this->toArray();

    if ($status and $this->status) {
      array_unshift($headers, $this->status->join());
    }

    return join("\r\n", $headers)."\r\n";
  }

  function __toString() {
    return $this->join();
  }

  function output() {
    if ($this->status instanceof ResponseStatus) {
      http_response_code($this->status->code());
    }

    foreach ($this->toArray() as $header) {
      header($header);
    }
  }

  function toArray() {
    $res = [];

    foreach ($this->headers as $name => $values) {
      foreach ($values as $value) {
        $res[] = "$name: $value";
      }
    }

    return $res;
  }
}
