<?php namespace Phiws;

class Protocols implements PluginInterface, \Countable, \IteratorAggregate {
  protected $protocols = [];
  protected $active;    // null or Protocol

  function add(Protocol $protocol) {
    $id = (string) $protocol->id();
    $dup = $this->get($id);

    if ($dup and $dup !== $protocol) {
      CodeException::fail("add($id): duplicate protocol ID");
    } elseif ($id === '') {
      // "If the client's handshake did not contain such a header field or if the server does not agree to any of the client's requested subprotocols, the only acceptable value is null. The absence of such a field is equivalent to the null value. [...] The empty string is not the same as the null value for these purposes and is not a legal value for this field."
      CodeException::fail("add: blank protocol ID");
    }

    $this->protocols[$id] = $protocol;
    return $this;
  }

  function addFrom(Protocols $protocols, $newContext = null) {
    foreach ($protocols as $proto) {
      $this->add($proto->cloneFor($newContext));
    }

    return $this;
  }

  function get($id) {
    return isset($this->protocols[$id]) ? $this->protocols[$id] : null;
  }

  function active(Protocol $proto = null) {
    if (!func_num_args()) {
      return $this->active;
    } elseif (isset($proto) and !in_array($proto, $this->protocols, true)) {
      CodeException::fail("active({$proto->id()}): new protocol is unlisted");
    } else {
      $this->active = $proto;
      return $this;
    }
  }

  function ids() {
    return array_keys($this->protocols);
  }

  #[\ReturnTypeWillChange]
  function count() {
    return count($this->protocols);
  }

  function reset() {
    $this->active = null;
    foreach ($this as $proto) { $proto->reset(); }
  }

  function clear() {
    $this->protocols = [];
    return $this;
  }

  function __clone() {
    foreach ($this->protocols as &$ref) {
      $ref = clone $ref;
    }
  }

  #[\ReturnTypeWillChange]
  function getIterator() {
    return new \ArrayIterator($this->protocols);
  }

  function isGlobalHook() {
    return false;
  }

  function events() {
    return ['clientBuildHeaders', 'clientCheckHeaders', 'serverCheckHeaders',
            'serverBuildHeaders'];
  }

  function clientBuildHeaders(Client $client, Headers\Bag $headers) {
    if ($this->protocols) {
      $headers->add('Sec-Websocket-Protocol', join(', ', $this->ids()));
    }
  }

  function clientCheckHeaders(Client $client, Headers\Bag $headers) {
    // Page 19, point 6.
    // "[...] MAY appear multiple times in an HTTP request (which is logically the same as a single |Sec-WebSocket-Protocol| header field that contains all values)."
    $header = $headers->get('Sec-Websocket-Protocol');

    if ($header !== null) {
      $proto = $this->get($header);

      if (!$proto) {
        StatusCodes\MalformedHttpHeader::fail("Sec-WebSocket-Protocol: unknown protocol [$header]");
      }
    }
  }

  // Page 23.
  function serverCheckHeaders(ServerClient $cx, Headers\Bag $headers) {
    $header = $headers->get('Sec-Websocket-Protocol');

    if ($header !== null) {
      $listed = [];

      foreach (explode(',', $header) as $id) {
        $id = trim($id);
        $obj = $listed[] = $this->get($id);

        if (!$obj) {
          StatusCodes\MalformedHttpHeader::fail("Sec-WebSocket-Protocol ($header): unknown protocol [$id]");
        }
      }

      $active = $this->pickClientProtocol($listed);

      if (!$active or !in_array($active, $listed, true)) {
          StatusCodes\MalformedHttpHeader::fail("Sec-WebSocket-Protocol ($header): no suitable protocol");
      }

      $this->active = $active;
    }
  }

  // Can be overriden to return a protocol based on some strategy.
  protected function pickClientProtocol(array $listed) {
    return reset($listed);
  }

  function serverBuildHeaders(ServerClient $cx, Headers\Bag $headers) { 
    // No Sec-WebSocket-Protocol header.
    // "If the client's handshake did not contain such a header field or [...], the only acceptable value is null."
    if ($this->active) {
      // "[...] the |Sec-WebSocket-Protocol| header field MUST NOT appear more than once in an HTTP response."
      $headers->set("Sec-Websocket-Protocol", $this->active->id());
    }
  }
}
