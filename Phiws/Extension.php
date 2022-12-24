<?php namespace Phiws;

abstract class Extension implements PluginInterface {
  // Must be set in subclasses. By convention, non-inHandshake() extensions' IDs are
  // '_' + class base name (e.g. '_MaxPayloadLength'). For inHandshake() this is a
  // registered IANA ID like 'permessage-deflate'.
  const ID = '';

  protected $tunnel;
  protected $params;

  function __construct() {
    $this->reset();
  }

  function cloneFor($newContext) {
    $clone = clone $this;
    $clone->tunnel = $newContext;
    return $clone;
  }

  function isGlobalHook() {
    return false;
  }

  // Any extension can act as plugin. After handshake, negotiated extensions will be
  // registered as if plugins()->add($ext) was called on each $extensions->active.
  // Prior to handshake completion they won't receive events.
  function events() {
    return [];
  }

  function id() {
    return static::ID;
  }

  function param($name) {
    return isset($this->params[$name]) ? $this->params[$name] : null;
  }

  function reset() {
    $this->useDefaultParams();
  }

  function suggestParams() {
    $this->useFallbackParams();
    return [$this->serializeParams()];
  }

  // Should set parameters with default values, as if they didn't appear in the
  // offer. For example, permessage-deflate implies window sizes of 15 and so
  // parse_...() won't be called if offer has no such parameters, yet param('window_size')
  // should return not null but 15.
  function useDefaultParams() {
    $this->params = [];
  }

  // validateParams() is not called since these values are not coming from the outside.
  function useFallbackParams(array $declinedParamSets = []) {
    if ($declinedParamSets) {
      $this->unserializeParams($declinedParamSets[0]);
      $retain = $this->retainOfferedParams();
    } else {
      $retain = null;
    }

    $this->useDefaultParams();
    $this->params = ((array) $retain) + $this->params;
  }

  function retainOfferedParams() {
    return [];
  }

  function unserializeParams(array $params) {
    $this->useDefaultParams();

    foreach ($params as $name => $value) {
      $func = "parse_$name";

      if (!method_exists($this, $func)) {
        // RFC 7692:
        // "A client MUST _Fail the WebSocket Connection_ if [...] The negotiation response contains an extension parameter not defined for use in a response."
        StatusCodes\NegotiationError::fail("$name: unknown parameter");
      }

      $res = $this->$func($value);
      isset($res) and $this->params[$name] = $res;
    }
  }

  // Return anything but null to set $this->params[$name] = $result.
  // Throw StatusCodes\NegotiationError on invalid value.
  //
  // RFC 7692:
  // "The negotiation offer contains an extension parameter with an invalid value."
  // "The client does not support the configuration [...]."
  //abstract protected function parse_paramName($value);

  // Throws NegotiationError.
  //
  // RFC 7692, page 6:
  // "A request in a PMCE negotiation offer indicates constraints on the server's behavior that must be satisfied if the server accepts the offer. [...] A hint in a PMCE negotiation offer provides information about the client's behavior that the server may either safely ignore or refer to when the server decides its behavior."
  function validateParams() { }

  function serializeParams() {
    $res = [];

    foreach (get_class_methods($this) as $func) {
      if (!strncmp($func, 'build_', 6)) {
        $name = substr($func, 6);
        $cur = $this->$func($this->param($name));

        if (!isset($cur)) {
          continue;
        } elseif (!is_array($cur)) {
          $cur = [$name => $cur];
        }

        $res += $cur;
      }
    }

    return $res;
  }

  // Return null (don't include into result), true (include without value),
  // string (empty permitted), array (multiple parameter => value).
  // $value is current value in $this->params[paramName], or null.
  //abstract protected function build_paramName($value);

  function isActive() {
    return true;
  }

  // Called when extension was included in request's list but server didn't include
  // it in response (so it was deactivated).
  // Return ClientExtensionsNotNegotiated or other Exception if this is critical.
  function notNegotiated() { }

  function inHandshake() {
    return true;
  }

  // Return null to not activate (include into the pipeline) - same as false in isActive().
  // Return '<' (start), '>' (end), other string - extension ID to insert this before.
  function position() {
    return '<';
  }

  // Return null to keep original $frames. Return a callable (is passed Pipeline)
  // that returns array of frames until all $frames are processed (e.g.
  // it may fragment them and return a single frame until all frames were fragmented).
  // For each returned set of frames, remaining extensions will be called. In the
  // end, sendRawFrames() is called with produced frame(s).
  function sendProcessor(array $frames, Pipeline $pipe) { }

  // Can throw ENotEnoughInput.
  function receiveProcessor(array $frames, Pipeline $pipe) { }

  protected function throwIfIncomplete(array $frames) {
    foreach ($frames as $frame) {
      if (!$frame->isComplete()) {
        Exceptions\ENotEnoughInput::fail($this->id().": complete frame required");
      }
    }
  }
}
