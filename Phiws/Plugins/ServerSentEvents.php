<?php namespace Phiws\Plugins;

use Phiws\CodeException;
use Phiws\DataSource;
use Phiws\Utils;

/*
  Note: data in this comment may be heavily outdated.

  SSE requires server-side configuration similar to WebSocket's (but simpler):
  - chunked_transfer_encoding must be off because:
    "Authors are also cautioned that HTTP chunking can have unexpected negative effects on the reliability of this protocol, in particular if the chunking is done by a different layer unaware of the timing requirements. If this is a problem, chunking can be disabled for serving event streams."
  - timeout must be adjusted to allow long-living connections, as well as idle connections
    (unless $keepAlive is used)
  - server -> client buffering must be disabled or made smaller (proxy_buffering in nginx; also possible to send X-Accel-Buffering: no)
  - nginx can be used to authenticate clients using a hashed URL parameter
  - number of simultaneous connections might need to be tweaked:
    "Clients that support HTTP's per-server connection limitation might run into trouble when opening multiple pages from a site if each page has an EventSource to the same domain. Authors can avoid this using the relatively complex mechanism of using unique domain names per connection, or by allowing the user to enable or disable the EventSource functionality on a per-page basis, or by sharing a single EventSource object using a shared worker."

  More on server -> client buffering (tested on Linux):
  - on default setups of Apache and nginx+fpm, doing flush() alone sends response in batches of about 4K (apparently because in production output_buffering is usually set to 4K; run-time ini_set() seems to have no effect); flush() can be replaced by ob_implicit_flush() in the beginning
  - ...doing ob_flush() alone or none of the two sends batches of about 8K
  - in nginx, the above batches are additionally subject to proxy_buffering which by default is 4K or 8K (i.e. if PHP's batch is smaller, it's still held up by nginx until it reaches proxy_buffering size)
  - unless disabled by proxy_ignore_headers, proxy_buffering can be turned off from the client size by sending X-Accel-Buffering: no
  - PHP buffers fwrite(fopen('php://output')) as it does with regular echo so flushing is still needed
  - the PHP Manual doesn't tell so but ob_flush() emits an Error if there is no buffering active (such as in CLI)

  Usage:

    // bootstrap.php
    $sse = new ServerSentEvents(80);

    $sse->onNewClient(function ($client, $sse) {
      // GET  /mysite/sse.php?hash=foo...&user=123  HTTP/1.1
      $request = $client->clientHeaders()->status();
      $givenHash = $request->parameter('hash');
      $userID = $request->parameter('user');
      $expectedHash = hash('sha256', $userID.config('secretKey'));

      // Constant-time comparison to prevent timing attacks.
      if (!hash_equals($expectedHash, $givenHash)) {
        // Deny access.
        return false;
      }

      // Access granted, prepare the state for serving events.
      $sse->userID = $userID;
    }

    $sse->onTick(function ($sse) {
      if ($sse->paused) { return; }

      $query = DB::query('messages')
        ->where_receiverID($sse->userID)
        ->where('id', '>', $sse->lastEventID)
        ->orderBy('time_created', 'DESC')
        ->select('id', 'message');

      foreach ($query as $row) {
        $event = new SSEvent($row->message);
        $event->id($row->id);
        $sse->queue($event);
      }
    });

    $sse->onIncomingFrame(function ($data, $sse) {
      switch ($data->readHead(10)) {
      case 'pause':
        return $sse->paused = true;
      case 'resume':
        return $sse->paused = false;
      }
    });

    // sse.php
    require 'bootstrap.php';
    $sse->startSSE();

    // daemon.php
    require 'bootstrap.php';
    $sse->startDaemon();

    // classes/Controllers/WebSocketBackend.php
    class WebSocketBackend extends \BaseController {
      // ...

      function get_index() {
        require 'bootstrap.php';
        $sse->startBackend();
      }
    }
*/

class ServerSentEvents extends \Phiws\StatefulPlugin {
  // "User agents may set Accept: text/event-stream in request's header list."
  // "HTTP 200 OK responses that have a Content-Type specifying an unsupported type, or that have no Content-Type at all, must cause the user agent to fail the connection."
  const SSE_MIME    = 'text/event-stream';

  const SSE         = 'SSE';
  const WS_DAEMON   = 'WS_DAEMON';
  const WS_BACKEND  = 'WS_BACKEND';

  const STOP        = 'STOP';
  const RESTART     = 'RESTART';

  protected $onTick;
  protected $onNewClient;
  protected $onIncomingFrame;

  // Seconds between empty comment blocks for SSE, Pong frames for WS.
  //
  // "Legacy proxy servers are known to, in certain cases, drop HTTP connections after a short timeout. To protect against such proxy servers, authors can include a comment line (one starting with a ':' character) every 15 seconds or so."
  protected $keepAliveInterval = 15;
  protected $server;
  protected $custom;

  // WS only; see options by the same name in phiws.js.
  protected $lastEventIdParameter = 'last-event-id';
  // SSE-only, can be null to disable.
  protected $stopSseEvent = 'phiws-stop';
  // SSE-only.
  protected $ssePrefixWithEventType = false;

  protected $lf = "\n";
  // SSE only, array of randomized min, max.
  protected $reconnectDelay = [1000, 5000];

  protected $mode;
  protected $stopping;
  protected $serverClient;
  protected $lastKeepAlive;

  function __construct($daemonPort, $daemonIP = '0.0.0.0') {
    $this->server = new \Phiws\Server($daemonPort, $daemonIP);
    $this->server->plugins()->add($this, true);

    $this->custom = new \stdClass;
    $this->custom->lastEventID = null;
  }

  /**
   * Accessors
   */

  // function ($this). If returns an SSEvent or an array of them - calls
  // queue() automatically.
  function onTick($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args());
  }

  // function ($this, ServerClient). If returns false or throws an exception
  // then the client is disconnected.
  //
  // - SSE - ServerClient is passed as null.
  // - WS_DAEMON - ServerClient is newly connected client.
  // - WS_BACKEND - ServerClient is the only active client.
  function onNewClient($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args());
  }

  // Only for WS_DAEMON and WS_BACKEND (SSE is unidirectional).
  // function (DataSource, $this). Partial frames and continuations are buffered
  // if this function is set.
  function onIncomingFrame($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args());
  }

  function loopWait($value = null) {
    if (func_num_args()) {
      $this->server->loopWait($value);
      return $this;
    } else {
      return $this->server->loopWait();
    }
  }

  function keepAliveInterval($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'int');
  }

  function server() {
    return $this->server;
  }

  function custom() {
    return $this->custom;
  }

  function lastEventIdParameter($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args());
  }

  function stopSseEvent($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args());
  }

  function ssePrefixWithEventType($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'bool');
  }

  function __get($name) {
    return $this->custom->$name;
  }

  function __set($name, $value) {
    $this->custom->$name = $value;
  }

  function __isset($name) {
    return isset($this->custom->$name);
  }

  function __unset($name) {
    unset($this->custom->$name);
  }

  /**
   * General
   */

  function reset() {
    if ($this->mode) {
      CodeException::fail("reset($this->mode): cannot reset while active");
    }

    $this->server->reset();
    $this->stopping = false;
    $this->serverClient and $this->serverClient->reset();
    $this->lastKeepAlive = null;
  }

  function log($msg, $e = null, $level = 0) {
    $this->mode and $msg = "$this->mode: $msg";
    $logger = $this->serverClient ?: $this->server;
    $logger->log($msg, $e, $level);
  }

  protected function setMode($mode) {
    if ($this->mode and $mode) {
      CodeException::fail("setMode($mode): $this->mode is already active");
    }

    $this->log($mode ? "setMode($mode)" : "setMode: stopped");
    $this->mode = $mode;
  }

  protected function tick() {
    $now = microtime(true);

    if ($this->lastKeepAlive < $now - $this->keepAliveInterval) {
      $this->lastKeepAlive = $now;
      $this->pong();
    }

    if ($this->onTick) {
      $res = call_user_func($this->onTick, $this);

      if (!$res) {
        // Skip.
      } elseif ($res instanceof SSEvent) {
        $this->queue($res);
      } elseif (is_array($res) and (reset($res) instanceof SSEvent)) {
        foreach ($res as $src) { $this->queue($src); }
      } else {
        $this->log("tick: onTick returned invalid value, ignored", null, 'warn');
      }
    }
  }

  // If $reconnectDelay is unset - clients are told to stop and not reconnect
  // (reconnecting is the default behaviour in SSE but not in WS). If set - clients
  // are told to reconnect, and the delay (ideally randomized) is implementation-dependent.
  // In WS the delay is described as 5-30 seconds (http://www.ietf.org/mail-archive/web/hybi/current/threads.html#09670),
  // in SSE server can specify it but it defaults to "a user-agent-defined value, probably in the region of a few seconds.".
  function stop($reconnect = false) {
    $this->log("stopping");
    $this->stopping = $reconnect ? static::RESTART : static::STOP;

    $statusCode = $reconnect ? new \Phiws\StatusCodes\ServiceRestart : null;
    $this->server->gracefulStop($statusCode);

    if ($this->serverClient and $this->mode === static::WS_BACKEND) {
      $this->serverClient->gracefulDisconnect($statusCode);
    }

    return $this;
  }

  /**
   * Server-Sent Events
   */

  function startSSE() {
    $this->reset();
    $this->setMode(static::SSE);

    header('Content-Type: '.static::SSE_MIME);

    try {
      if ($this->onNewClient and call_user_func($this->onNewClient, null, $this) === false) {
        \Phiws\StatusCodes\PolicyViolation::fail();
      }
    } catch (\Throwable $e) {
      goto ex;
    } catch (\Exception $e) {
      ex:
      // XXX Must include the relevant HTTP code instead of 204 because:
      // "Servers should use a 5xx status code to indicate capacity problems, as this will prevent conforming clients from reconnecting automatically."
      // https://www.w3.org/TR/eventsource/#iana-considerations
      $this->sendNoReconnectSSE();
      throw $e;
    }

    // "If the field value consists of only ASCII digits, then [...] set the event stream's reconnection time to that integer. Otherwise, ignore the field."
    $delay = mt_rand($this->reconnectDelay[0], $this->reconnectDelay[1]);
    echo "retry:$delay$this->lf";
    echo $this->lf;

    $this->loopSSE();

    if ($this->stopping === static::STOP) {
      $this->sendNoReconnectSSE();
    }

    $this->setMode(null);
  }

  function sendNoReconnectSSE() {
    if ($this->mode === static::SSE) {
      if (headers_sent()) {
        $event = new SSEvent('', $this->stopSseEvent);
        $event->retry(1000 * 3600 * 24 * 365);
        $this->sendSSE($event);
      } else {
        // "Clients will reconnect if the connection is closed; a client can be told to stop reconnecting using the HTTP 204 No Content response code."
        http_response_code(204);
        echo "Do Not Reconnect";
      }

      $this->flushSSE();
    }
  }

  protected function loopSSE() {
    if (!isset($this->custom->lastEventID) and isset($_SERVER['HTTP_LAST_EVENT_ID'])) {
      $this->custom->lastEventID = $_SERVER['HTTP_LAST_EVENT_ID'];
    }

    while (!$this->stopping) {
      if (isset($time)) {
        $rest = $this->loopWait() - (microtime(true) - $time) * 1000;
        $rest > 0 and usleep(1000 * $rest);
      }

      if (connection_aborted()) {
        $this->log('loopSSE: client disconnected');
        break;
      }

      $time = microtime(true);
      try {
        $this->tick();
      } catch (\Throwable $e) {
        goto ex;
      } catch (\Exception $e) {
        ex:
        $this->log('loopSSE: tick exception', $e, 'error');
      }

      $this->flushSSE();
    }
  }

  protected function flushSSE() {
    ob_flush();
    flush();
  }

  /**
   * WebSocket
   */

  function startDaemon() {
    $this->reset();
    $this->setMode(static::WS_DAEMON);

    $this->server->start();
    $server->loop();

    $this->setMode(null);
  }

  function startBackend(array $serverInfo = null) {
    $serverInfo or $serverInfo = $_SERVER;
    $this->reset();
    $this->setMode(static::WS_BACKEND);

    $client = $this->serverClient = \Phiws\ServerClient::forOutput($serverInfo);
    $client->handshake();
    $client->loop();

    $this->setMode(null);
  }

  function events() {
    return ['serverClientConnected', 'loopTick',
            'pickProcessorFor', 'bufferedFrameComplete'];
  }

  function serverClientConnected(\Phiws\ServerClient $cx) {
    // This event occurs after plugins have been cloned, so this one is independent
    // of the original that exists in Server->plugins().
    $this->serverClient = $client;

    if (!isset($this->custom->lastEventID) and isset($_GET[$this->lastEventIdParameter])) {
      $this->custom->lastEventID = $_GET[$this->lastEventIdParameter];
    }

    try {
      if ($this->onNewClient and call_user_func($this->onNewClient, $cx, $this) === false) {
        $this->log('onNewClient has rejected the client, disconnecting');
        \Phiws\StatusCodes\PolicyViolation::fail();
      }
    } catch (\Throwable $e) {
      $cx->disconnect($e);
    } catch (\Exception $e) {
      $cx->disconnect($e);
    }
  }

  function loopTick(\Phiws\BaseObject $cx, $maxWait, $iterDuration) {
    // Don't tick on the Server itself but for each connected client.
    $this->serverClient and $this->tick();
  }

  function pickProcessorFor(\Phiws\BaseTunnel $cx, &$res, \Phiws\DataFrame $frame) {
    if ($func = $this->onIncomingFrame) {
      $res = new \Phiws\DataProcessors\BufferAndTrigger($frame);
      return false;
    }
  }

  function bufferedFrameComplete(\Phiws\BaseTunnel $cx, DataSource $applicationData = null, DataSource $extensionData = null) {
    call_user_func($this->onIncomingFrame, $applicationData, $this);
  }

  /**
   * Common methods (data sending)
   */

  function pong() {
    $this->log("sending keep-alive");

    if ($this->mode === static::SSE) {
      $msg = "keep-alive ".time();
      $this->sendSSE((new SSEvent)->comment($msg));
    } elseif ($this->serverClient) {
      $this->serverClient->queueUnsolicitedPong();
    }
  }

  function queue(SSEvent $event) {
    if ($event->isEmpty()) {
      $this->log('queue: ignoring empty event');
    } else {
      $id = $event->id();
      $data = $event->dataSource();
      $this->log(sprintf("queue(%s%s): [%d] %s", $event->eventOrDefault(),
        isset($id) ? "#$id" : '',
        $data ? $data->size() : 0, $data ? $data->readHead(20) : ''));

      if ($this->mode === static::SSE) {
        $this->sendSSE($event);
      } elseif ($this->serverClient) {
        $this->sendWS($event);
      }
    }

    return $this;
  }

  protected function sendWS(SSEvent $event) {
    // Event properties are sent as a JSON text frame.
    // Event data is sent as a binary frame (since in SSE all messages are string).
    // Both frames are optional. This separation allows live stream-reading of the
    // data from the handle without buffering it here.
    //
    // For example, if event has id and comment then only text frame is sent.
    // For event with only data - only binary frame is sent.
    // For event with id and data - a text frame is sent, followed by a binary frame.
    // It can look like this (numbers indicate separate frames):
    //
    //    T  T  T B  B  T B  T  T B
    //    1  2  3 3  4  5 5  6  7 7
    //
    //    1, 2, 6 - no data
    //    3, 7 - complete frames (properties and data)
    //    4 - only data

    $info = $event->fieldArray();
    $src = $event->dataSource();

    if ($info) {
      $info['hasData'] = (bool) $src;
      $this->serverClient->queueJsonData($info);
    }

    if ($src) {
      $this->serverClient->queueBinaryData($src);
    }
  }

  // https://html.spec.whatwg.org/multipage/comms.html#server-sent-events
  protected function sendSSE(SSEvent $event) {
    $triggersClient = $event->triggersClient();
    $fields = $event->fieldArray('event');
    ksort($fields);   // it's more pretty if comment goes before others

    if ($triggersClient and $this->ssePrefixWithEventType) {
      echo "data:{$event->event()}$this->lf";
      unset($fields['event']);
    }

    foreach ($fields as $key => $value) {
      if ($key === 'comment') {
        echo ':', $this->prefixLines(':', $event->comment());
      } else {
        echo "$key:$value$this->lf";
      }
    }

    if ($src = $event->dataSource()) {
      echo 'data:';
      $src->readChunks(8192, function ($chunk) {
        echo $this->prefixLines('data:', $chunk);
      });
    } elseif ($triggersClient) {
      // Per the spec, data must be non-empty for the event to be fired. Work around this by supplying empty data: which turns into '\n' and then '' when parsed.
      //
      // "If the data buffer is an empty string, set the data buffer and the event type buffer to the empty string and return."
      echo "data$this->lf";
    }

    echo $this->lf;
  }

  // Doesn't add $prefix in front of first line.
  function prefixLines($prefix, $chunk) {
    $chunk = strtr($chunk, [
      "\r\n"  => $this->lf.$prefix,
      "\n"    => $this->lf.$prefix,
      "\r"    => $this->lf.$prefix,
    ]);

    return $chunk.$this->lf;
  }

  // https://html.spec.whatwg.org/multipage/comms.html#event-stream-interpretation
  //
  // Unused right now but was implemented while I was parsing the spec.
  // Calls $func with an array of 'field' => 'value', where field === '' indicates
  // comment lines (folded together with \n like 'data'). An array can't be empty
  // but it can contain only comments: ['' => "only\ncomments"].
  protected function parseSSE($handle, $func) {
    $current = [];
    $eatBOM = true;
    $remainder = '';

    while (true) {
      // 1. fget() doesn't recognize \r as required by the spec (9.2.4 Parsing an event stream).
      // 2. fget() can return a partial line if it's longer than the length specified.
      // 3. fget() can return multiple lines including a possible partial line if \r
      //    separator is used.
      $line = EStream::callType('string', 'fgets', $handle, 8192);

      if ($line === '') {
        // End of stream or disconnection.
        break;
      }

      $line = $remainder.$line;
      $tail = substr($line, -2);
      $complete = (substr($line, -1) === "\n" or substr($line, -1) === "\r" or substr($line, -2) === "\r\n");

      if ($complete) {
        $remainder = '';
      } else {
        $pos1 = strlen(strrchr($line, "\r"));
        $pos2 = strlen(strrchr($line, "\n"));
        $pos = ($pos1 and $pos2) ? min($pos1, $pos2) : ($pos1 ?: $pos2);

        if (!$pos) {
          // A very long line - no line break in this chunk.
          $remainder = $line;
          continue;
        }

        $remainder = substr($line, -$pos);
        $line = substr($line, 0, -strlen($remainder));
      }

      if ($eatBOM) {
        $eatBOM = false;

        // "The UTF-8 decode algorithm strips one leading UTF-8 Byte Order Mark (BOM), if any."
        if (substr($line, 0, 2) === "\xFF\xFE") {
          $line = substr($line, 2);
        }
      }

      // "If value starts with a U+0020 SPACE character, remove it from value."
      $re = '~^ ([^:\r\n]*) (:[ ]?(.*)) ()$~mux';

      if (!preg_match_all($re, $line, $matches, PREG_SET_ORDER)) {
        CodeException::fail("parseSSE: malformed block");
      }

      foreach ($matches as $match) {
        list($full, $key, , $value) = $match;

        if (!strlen($full)) {
          $current and call_user_func($func, $current);
          $current = [];
        } else {
          // Event field names are case-sensitive:
          // "Field names must be compared literally, with no case folding performed."
          $ref = &$res[$key];

          if (isset($ref) and ($key === '' or $key === 'data')) {
            // Even this is a valid event block producing a 'data' field consisting
            // of just a line break (e.g. '\n'):
            //
            //   data
            //   data
            $ref .= "\n$value";
          } else {
            $ref = $value;
          }
        }
      }

      // XXX Check if this is implemented:
      // "If the data buffer's last character is a U+000A LINE FEED (LF) character, then remove the last character from the data buffer."
    }

    // $current here is not used if the stream dind't end with a blank line:
    // "Once the end of the file is reached, any pending data must be discarded. (If the file ends in the middle of an event, before the final empty line, the incomplete event is not dispatched.)"
  }
}

class SSEvent {
  const DEFAULT_EVENT = 'message';

  // Defaults to "message" as per the spec, both when 'id' is sent empty or not sent.
  protected $event;
  // Client will transmit this value (numeric or not) in Last-Event-Id header
  // upon reconnect, unless it's empty or omitted. Empty/omitted IDs replace
  // filled IDs so if a client reconnects after having received two events with
  // 'id: 123' and with no 'id' or with empty ('id' or 'id:') then it won't send
  // Last-Event-ID header.
  protected $id;
  protected $data;
  protected $comment;
  protected $retry;

  protected $dataSource;

  function __construct($data = '', $event = null, $id = null) {
    $this->data($data);
    $this->event($event);
    $this->id($id);
  }

  function event($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), [$this, 'normEvent']);
  }

  function eventOrDefault() {
    return $this->event === '' ? static::DEFAULT_EVENT : $this->event;
  }

  function id($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), [$this, 'normNoBreaks']);
  }

  // $value - string, PHP handle (autofreed), DataSource.
  function data($value = null) {
    func_num_args() and $this->dataSource = null;
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args());
  }

  // $value - string only.
  function comment($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'string');
  }

  function retry($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'int');
  }

  function normEvent($value) {
    $value = (string) $value;
    $this->normNoBreaks($value);

    if ($value === static::DEFAULT_EVENT) {
      return '';
    } else {
      return $value;
    }
  }

  function normNoBreaks($value) {
    if (strpbrk($value, "\r\n")) {
      CodeException::fail("normNoBreaks: this event field cannot contain line breaks");
    }
  }

  // Returns null if data isn't assigned or has zero length.
  function dataSource() {
    if (!$this->dataSource) {
      if (!isset($this->data)) {
        // Okay.
      } elseif (is_object($this->data)) {
        if (!($this->data instanceof DataSource)) {
          CodeException::fail("dataSource: invalid value");
        } elseif ($this->data->size()) {
          $this->dataSource = $this->data;
        }
      } elseif (is_string($this->data)) {
        if (strlen($this->data)) {
          $this->dataSource = new \Phiws\DataSources\StringDS($this->data);
        }
      } elseif (is_resource($this->data)) {
        $this->dataSource = \Phiws\DataSources\Stream($this->data, true);
      } else {
        CodeException::fail("dataSource: invalid value");
      }
    }

    return $this->dataSource;
  }

  function fieldArray($eventKeyName = 'type') {
    $res = [];
    strlen($this->comment) and $res['comment'] = $this->comment;
    strlen($this->event) and $res[$eventKeyName] = $this->event;
    isset($this->id) and $res['id'] = $this->id;
    isset($this->retry) and $res['retry'] = $this->retry;
    return $res;
  }

  function isEmpty() {
    return !$this->dataSource() and !$this->fieldArray();
  }

  // Returns true if this event object has data for handling by JS code, not
  // browser internals (like comment and retry which are not exposed).
  function triggersClient() {
    $meta = ['comment', 'retry'];
    return $this->dataSource() or array_diff(array_keys($this->fieldArray()), $meta);
  }
}
