<?php namespace Phiws;

class Extensions implements PluginInterface {
  const HEADER = 'Sec-Websocket-Extensions';

  // Null or BaseTunnel.
  protected $tunnel;
  protected $extensions = [];
  protected $active = [];

  function __construct($tunnel = null) {
    $this->tunnel = $tunnel;
  }

  // Can be null.
  function tunnel() {
    return $this->tunnel;
  }

  function all() {
    return $this->extensions;
  }

  function active() {
    return $this->active;
  }

  function activeIDs() {
    return array_keys($this->active);
  }

  function reset() {
    $this->unregisterHooks();
    $this->active = [];
    foreach ($this->extensions as $ext) { $ext->reset(); }
  }

  function log($msg, $e = null, $level = 0) {
    $this->tunnel and $this->tunnel->log($msg, $e, $level);
    return $this;
  }

  // "Note that the order of extensions is significant. Any interactions between multiple extensions MAY be defined in the documents defining the extensions. In the absence of such definitions, the interpretation is that the header fields listed by the client in its request represent a preference of the header fields it wishes to use, with the first options listed being most preferable. The extensions listed by the server in response represent the extensions actually in use for the connection."
  function add(Extension $ext) {
    $id = $ext->id();

    if (isset($this->extensions[$id])) {
      CodeException::fail("add($id): duplicate IDs");
    } elseif (!strlen($id)) {
      CodeException::fail("add: ID cannot be blank");
    }

    $this->extensions[$id] = $ext->cloneFor($this->tunnel);
  }

  function addFrom(Extensions $exts) {
    foreach ($exts->all() as $ext) {
      $this->add($ext);
    }

    return $this;
  }

  function get($id) {
    if (!isset($this->extensions[$id])) {
      CodeException::fail("get($id): unknown ID");
    }

    return $this->extensions[$id];
  }

  /**
   * Send/Receive Pipeline
   */

  function send(array $origFrames, $terminator) {
    return $this->process( $this->makePipeline($origFrames, $terminator, true) );
  }

  function receive(array $origFrames, $terminator) {
    return $this->process( $this->makePipeline($origFrames, $terminator, false) );
  }

  protected function makePipeline(array $frames, $terminator, $forward) {
    $proc = new Pipeline;

    $proc->extensions = $this;
    $proc->frames     = $frames;
    $proc->terminator = $terminator;
    $proc->method     = $forward ? 'sendProcessor' : 'receiveProcessor';
    $proc->ids        = $this->activeIDs();
    $proc->forward    = $forward;
    $proc->isClient   = $this->tunnel instanceof Client;

    if ($this->tunnel and $this->tunnel->logger()->logs()) {
      $proc->logger = $this->tunnel;
    }

    $proc->logFrames($proc->method);
    return $proc;
  }

  protected function process(Pipeline $proc) {
    if (!$proc->frames) {
      return $this->log("$proc->method: no frames to process");;
    }

    if ($id = $proc->shiftID()) {
      $ext = $this->active[$id];
      $proc->logFrames("$proc->method($id)");
      $func = $ext->{$proc->method}($proc->frames, $proc);

      if ($func) {
        while ($frames = call_user_func($func, $proc)) {
          if (!is_array($frames)) {
            CodeException::fail("{$ext->id()}->$proc->method must return an array of Frame's");
          }

          $subproc = clone $proc;
          $subproc->frames = $frames;
          $this->process($subproc);
        }
      } else {
        $this->log("$id skipped processing...");
        $this->process($proc);
      }
    } else {
      $this->log("$proc->method: terminator reached");
      call_user_func($proc->terminator, $proc->frames, $proc);
    }
  }

  /**
   * Negotiation
   */

  function isGlobalHook() {
    return false;
  }

  function events() {
    return ['clientBuildHeaders', 'clientCheckHeaders', 'serverCheckHeaders',
            'serverBuildHeaders'];
  }

  // Client-side handshake:
  // v isActive/inHandshake
  // v suggestParams
  // o - server response -
  // +----> missing from response? notNegotiated
  // v unserializeParams
  // v validateParams
  // o - negotiated -
  // v plugins->add(ext)
  function clientBuildHeaders(Client $cx, Headers\Bag $headers) {
    foreach ($this->extensions as $ext) {
      if ($ext->isActive() and $ext->inHandshake()) {
        $this->log("Extensions: activating {$ext->id()}");
        $lowerID = strtolower($ext->id());
        $paramSets = $ext->suggestParams();

        if (isset($this->active[$lowerID])) {
          CodeException::fail("clientBuildHeaders($lowerID): in-handshake extension must have unique case-insensitive IDs");
        } elseif (!$paramSets) {
          // Possible logic error. If there are no params but the extension want to
          // be negotiated - then [[]] must be returned (meaning "one suggestion with
          // no parameters"). [] means the are no negotiable choices - and if so then
          // why isActive() returned true?
          CodeException::fail("clientBuildHeaders({$ext->id()}): isActive() but empty suggestParams()");
        }

        // RFC 7692:
        // "A client may also offer multiple PMCE choices to the server by including multiple elements in the "Sec-WebSocket-Extensions" header, one for each PMCE offered.  This set of elements MAY include multiple PMCEs with the same extension name to offer the possibility to use the same algorithm with different configuration parameters."
        foreach ($paramSets as $params) {
          $headers->addParametrized(static::HEADER, $ext->id(), $params);
        }

        $this->active[$lowerID] = $ext;
      }
    }
  }

  // Page 19, point 5, and the last paragraph in 4.1.
  // Page 23, last paragraph: /extensions/.
  function clientCheckHeaders(Client $cx, Headers\Bag $headers) {
    // Errata 3433: "The |Sec-WebSocket-Extensions| header field MAY appear multiple times in an HTTP response (which is logically the same as a single |Sec-WebSocket-Extensions| header field that contains all values)."
    $header = $headers->getParametrizedTokens(static::HEADER, true);
    $parsed = [];

    foreach ($header as $item) {
      list($id, $params) = $item;
      $ext = isset($this->active[$id]) ? $this->active[$id] : null;

      if (isset($parsed[$id])) {
        StatusCodes\MalformedHttpHeader::fail(static::HEADER.": duplicate extension ID [$id]");
      } elseif (!$ext or !$ext->inHandshake()) {
        // "[...] the use of an extension that was not present in the client's handshake (the server has indicated an extension not requested by the client), the client MUST _Fail the WebSocket Connection_."
        StatusCodes\NegotiationError::fail("extension [$id] present in response was not suggested");
      } else {
        $parsed[$id] = $ext;
        // RFC 7692, page 14:
        // "A client MUST _Fail the WebSocket Connection_ if [...]"
        // "The negotiation response contains an extension parameter not defined for use in a response."
        // "The negotiation response contains an extension parameter with an invalid value."
        // "The client does not support the configuration that the response represents."
        $ext->unserializeParams($this->parseHeaderParams($params));
        $ext->validateParams();
      }
    }

    foreach (array_diff_key($this->active, $parsed) as $ext) {
      $this->log("Extensions: {$ext->id()} not negotiated in response");
      $ex = $ext->notNegotiated();
      $ex and $cx->disconnectAndThrow($ex);
    }

    // "Should the extensions modify the data and/or framing, the order of operations on the data should be assumed to be the same as the order in which the extensions are listed in the server's response in the opening handshake."
    $this->active = $parsed;
    $this->insertOffHandshakes();
    $this->registerHooks();

    $ids = join(', ', $this->activeIDs());
    $this->log("Extensions: negotiated ".($ids ? $ids : 'none'));
  }

  protected function parseHeaderParams(array $params) {
    $assoc = [];

    foreach ($params as $item) {
      list($key, $value) = $item;

      if (isset($assoc[$key])) {
        // RFC 7692, page 14:
        // "A client MUST _Fail the WebSocket Connection_ if [...] The negotiation response contains multiple extension parameters with the same name."
        StatusCodes\MalformedHttpHeader::fail(static::HEADER.": duplicate parameter name [$key]");
      }

      $assoc[$key] = $value;
    }

    return $assoc;
  }

  // page 21, item 9
  // section 5.8
  // Page 23, last paragraph: /extensions/.
  // Page 25, item 6.
  //
  // Server-side handshake:
  // v known & inHandshake
  // +----> skip
  // v for each parameter set in the offer:
  // | v unserializeParams
  // | v validateParams
  // | +----> error - more sets?
  // | |    +----> no - useFallbackParams
  // | |    '> yes - continue
  // | '> success - break
  // v serializeParams
  // o - response sent to client -
  // v plugins->add(ext)
  function serverCheckHeaders(ServerClient $cx, Headers\Bag $headers) {
    $header = $headers->getParametrizedTokens(static::HEADER, true);
    $byID = [];

    foreach ($header as $item) {
      list($id, $params) = $item;
      $ref = &$byID[$id];
      $ref or $ref = [];
      $ref[] = $params;
    }

    foreach ($byID as $id => $paramSets) {
      try {
        $ext = $this->get($id);
      } catch (\Throwable $e) {
        $ext = null;
      } catch (\Exception $e) {
        $ext = null;
      }

      if (!$ext or !$ext->inHandshake()) {
        $this->log("serverCheckHeaders($id): unknown extension in the offer");
        continue;
      }

      $pickException = null;

      // RFC 7692:
      // "The order of elements is important as it specifies the client's preference. An element preceding another element has higher preference."
      //
      // RFC 7692, page 13:
      // "A server MUST decline [...] The negotiation offer contains multiple extension parameters with the same name."
      foreach ($paramSets as &$ref) {
        try {
          $ref = $this->parseHeaderParams($ref);

          // RFC 7692, page 13:
          // "A server MUST decline [...]"
          // "The negotiation offer contains an extension parameter not defined for use in an offer."
          // "The negotiation offer contains an extension parameter with an invalid value."
          // "The server doesn't support the offered configuration."
          $ext->unserializeParams($ref);
          $ext->validateParams();

          $pickException = null;
          break;
        } catch (\Throwable $e) {
          $pickException = $e;
        } catch (\Exception $e) {
          $pickException = $e;
        }
      }

      if ($pickException) {
        // If the client has suggested a known extension but the offer couldn't be
        // used as is - let's suggest the default, supported parameters in response.
        // The client will close the connection if he doesn't want them, as per the RFC.
        $this->log("serverCheckHeaders($id): no suitable parameter set offered, using fallback parameters", $pickException, 'warn');
        $ext->useFallbackParams($paramSets);
      }

      $this->active[$id] = $ext;
    }

    $this->insertOffHandshakes();
    $this->registerHooks();
  }

  // RFC 7692:
  // "A server MUST NOT accept a PMCE extension negotiation offer together with another extension if the PMCE will conflict with the extension on their use of the RSV1 bit."
  function serverBuildHeaders(ServerClient $cx, Headers\Bag $headers) {
    $this->log("Extensions: negotiated ".join(', ', $this->activeIDs()));

    foreach ($this->active as $id => $ext) {
      if ($ext->inHandshake()) {
        // RFC 7692, page 7:
        // "The contents of the element don't need to be exactly the same as those of the received extension negotiation offers."
        $headers->addParametrized(static::HEADER, $id, $ext->serializeParams());
      }
    }
  }

  protected function insertOffHandshakes() {
    foreach ($this->extensions as $ext) {
      if (!$ext->inHandshake() and $ext->isActive()) {
        $this->insertOffHandshakeAt($ext->position(), $ext);
        $regen = true;
      }
    }

    // array_splice() doesn't save keys in $replacement.
    isset($regen) and $this->regenerateActiveIDs();
  }

  protected function insertOffHandshakeAt($pos, Extension $ext) {
    if ($pos === '<') {
      $pos = 0;
    } elseif ($pos === '>') {
      $pos = count($this->active);
    } elseif (is_string($pos)) {
      $pos = array_search($pos, array_keys($this->active), true);
    }

    if ($pos !== false and $pos !== null) {
      array_splice($this->active, $pos, 0, [$ext]);
    }
  }

  protected function regenerateActiveIDs() {
    $ids = [];

    foreach ($this->active as $ext) {
      $ids[] = strtolower($ext->id());
    }

    $ids and $this->active = array_combine($ids, $this->active);
  }

  protected function registerHooks() {
    if ($this->tunnel) {
      $plugins = $this->tunnel->plugins();

      foreach ($this->active as $ext) {
        // Persistent so they don't show up in regular (non-persistent) user list of plugins.
        $plugins->add($ext, true);
      }
    }
  }

  protected function unregisterHooks() {
    if ($this->tunnel) {
      $plugins = $this->tunnel->plugins();

      foreach ($this->active as $ext) {
        $plugins->remove($ext);
      }
    }
  }
}
