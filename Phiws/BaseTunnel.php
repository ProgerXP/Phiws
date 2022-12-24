<?php namespace Phiws;

use Phiws\Exceptions\EState;

abstract class BaseTunnel extends BaseObject {
  const CONNECTING = 'CONNECTING';
  const OPEN = 'OPEN';
  const CLOSED = 'CLOSED';

  const PHIWS_VERSION = 0.91;
  const WS_VERSION = 13;
  const ACCEPT_KEY_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
  // "[...] MUST be a nonce consisting of a randomly selected 16-byte value [...]"
  const KEY_LENGTH = 16;

  static protected $globalPlugins = [];

  // Bigger frames will be processed in chunks. 256 KiB by default.
  protected $maxFrame = 262144;
  // If null uses $maxFrame.
  protected $maxQueuePayloads;

  // Null (don't check/change), true (require mask === true), false (not masked).
  // For inbound throws if frame's $mask doesn't match.
  // For outbound, if true - sets Maskers\Xor32 to frames without a masker,
  // if false - removes masker from all frames.
  protected $inboundMasked;
  protected $outboundMasked;

  protected $state = 'CLOSED';

  // Native PHP file streams (not socket handles).
  protected $inHandle;
  protected $outHandle;

  // Binary string (16).
  protected $key;
  protected $version;

  // Null or PartialRead.
  protected $readingPartial;
  protected $shortMessageBuffer;

  protected $writingState;
  protected $readingState;
  protected $queue;

  // Handles' opening timestamp.
  protected $connectedSince;

  protected $clientHeaders;
  protected $serverHeaders;

  // Extensions.
  protected $extensions;
  // Protocols.
  protected $protocols;

  // Page 19, point 4.
  // Page 24, point 5.4.
  // $key - binary.
  static function expectedServerKey($key) {
    $guid = static::ACCEPT_KEY_GUID;
    return base64_encode(hash('sha1', base64_encode($key).$guid, true));
  }

  // globalPlugins() - return array of current plugins
  // globalPlugins(Plugin) - add new plugin as global (safe even if exists)
  // globalPlugins(array) - replace current array of plugins
  static function globalPlugins($value = null) {
    if ($value === null) {
      return static::$globalPlugins;
    } elseif (is_array($value)) {
      return static::$globalPlugins = $value;
    } elseif ($value instanceof PluginInterface) {
      return static::$globalPlugins[] = $value;
    } else {
      CodeException::fail("globalPlugins: wrong arguments");
    }
  }

  protected function init() {
    parent::init();

    $this->clientHeaders = new Headers\Bag;
    $this->serverHeaders = new Headers\Bag;
    $this->extensions = new Extensions($this);
    $this->protocols = new Protocols($this);

    $this->plugins->add($this->extensions, true);
    $this->plugins->add($this->protocols, true);

    foreach (static::globalPlugins() as $plugin) {
      $this->plugins->add($plugin->cloneFor($this));
    }
  }

  function __destruct() {
    $this->disconnect();
  }

  /**
   * Accessors
   */

  function state() {
    return $this->state;
  }

  function clientHeaders() {
    return $this->clientHeaders;
  }

  function serverHeaders() {
    return $this->serverHeaders;
  }

  function key() {
    return $this->key;
  }

  function version() {
    return $this->version;
  }

  function maxFrame($value = null) {
    return Utils::accessor($this, $this->maxFrame, func_get_args(), 'int');
  }

  function maxQueuePayloads($value = null) {
    $res = Utils::accessor($this, $this->maxQueuePayloads, func_get_args(), 'int');
    return isset($res) ? $res : $this->maxFrame();
  }

  function readingPartial() {
    return Utils::cloneReader($this->{__FUNCTION__});
  }

  // Not cloned because it has fields for writing.
  function writingState() {
    return $this->writingState;
  }

  function readingState() {
    return $this->readingState;
  }

  function queue() {
    return $this->queue;
  }

  function queueCount() {
    return count($this->queue);
  }

  function queueLength() {
    return Frame::sumPayloadLengths($this->queue);
  }

  function inHandle() {
    return $this->inHandle;
  }

  function outHandle() {
    return $this->outHandle;
  }

  function connectedSince() {
    return $this->connectedSince;
  }

  function extensions() {
    return $this->extensions;
  }

  function protocols() {
    return $this->protocols;
  }

  function uptime() {
    return time() - $this->connectedSince;
  }

  function isConnected() {
    return $this->state === static::OPEN;
  }

  function isOperational() {
    return $this->isConnected() and !$this->writingState->closeFrame and
      !$this->readingState->closeFrame;
  }

  function isCleanClose() {
    return $this->writingState->closeFrame and $this->readingState->closeFrame;
  }

  function closeStatusCode() {
    if (!$this->readingState->closeFrame) {
      // "If _The WebSocket Connection is Closed_ and no Close control frame was received by the endpoint (such as could occur if the underlying transport connection is lost), _The WebSocket Connection Close Code_ is considered to be 1006."
      return new StatusCodes\AbnormalClosure;
    } else {
      $code = $this->readingState->closeFrame->statusCode();
      // "If this Close control frame contains no status code, _The WebSocket Connection Close Code_ is considered to be 1005."
      return $code ? clone $code : new StatusCodes\NoStatusReceived;
    }
  }

  function isStoppingLoop() {
    return !$this->isConnected();
  }

  /**
   * Disconnection
   */

  function reset() {
    try {
      $this->disconnect();
    } catch (\Throwable $e) {
      $exception = $e;
    } catch (\Exception $e) {
      $exception = $e;
    }

    $this->key = null;
    $this->version = null;
    $this->readingPartial = null;
    $this->shortMessageBuffer = null;

    $this->writingState = new DirectionState($this);
    $this->readingState = new DirectionState($this);
    $this->queue = [];

    $this->connectedSince = null;

    $this->clientHeaders->clear();
    $this->serverHeaders->clear();

    $this->extensions->reset();
    $this->protocols->reset();

    if (isset($exception)) { throw $exception; }

    return parent::reset();
  }

  // $maxWait - ms (1000 = 1 s), 0/null for default. Returns true on clean close, null on timeout.
  function gracefulDisconnectAndWait($maxWait = null, StatusCode $code = null) {
    $maxWait or $maxWait = 600;
    $this->gracefulDisconnect($code);
    $time = microtime(true);

    while ($this->isConnected() and microtime(true) - $time <= $maxWait) {
      $this->processMessages();
    }

    if ($this->isConnected()) {
      $this->disconnect();
    } else {
      return true;
    }
  }

  // Waits for client's Close frame.
  // 5.5.1: "After both sending and receiving a Close message, an endpoint considers the WebSocket connection closed and MUST close the underlying TCP connection. The server MUST close the underlying TCP connection immediately; the client SHOULD wait for the server to close the connection but MAY close the connection at any time after sending and receiving a Close message, e.g., if it has not received a TCP Close from the server in a reasonable time period."
  //
  // With $forceOnReattempt, client is given only one chance to close gracefully.
  // gracefulDisconnect(); gracefulDisconnect(); - second close will call
  // disconnect() immediately because a closing attempt was already made.
  function gracefulDisconnect(StatusCode $code = null, $forceOnReattempt = true) {
    if ($this->writingState->closeFrame and $forceOnReattempt) {
      $this->disconnect($code);
    } else {
      try {
        $this->fire(__FUNCTION__, [$code]);
        $this->sendClose($code);
      } catch (\Throwable $e) {
        goto ex;
      } catch (\Exception $e) {
        ex:
        $this->log('gracefulDisconnect: exception, forcing', $e, 'warn');
        $this->disconnect($code);
      }
    }

    return $this;
  }

  // "If an endpoint receives a Close frame and did not previously send a Close frame, the endpoint MUST send a Close frame in response. (When sending a Close frame in response, the endpoint typically echos the status code it received.)"
  function gracefulDisconnectInResponseTo(Frames\Close $frame) {
    return $this->gracefulDisconnect($frame->statusCode());
  }

  function disconnectAndThrow(object $e) {
    $this->disconnect(($e instanceof StatusCode) ? $e : new StatusCodes\InternalError(get_class($e)));
    throw $e;
  }

  function disconnect(StatusCode $code = null) {
    $closed = $this->state === static::CLOSED;

    if (!$closed) {
      try {
        $this->fire(__FUNCTION__, [$code]);

        // "If _The WebSocket Connection is Established_ prior to the point where the endpoint is required to _Fail the WebSocket Connection_, the endpoint SHOULD send a Close frame [...] before proceeding to _Close the WebSocket Connection_."
        // "Established" here means after completing the handshake (end of section 4.1). Once it happens $state becomes OPEN.
        if ($this->isConnected()) {
          $this->sendClose($code);
        } else {
          $this->sendHandshakeError($code);
        }
      } catch (\Throwable $e) {
        goto ex;
      } catch (\Exception $e) {
        ex:
        $this->log('disconnect: silenced exception', $e);
      }
    }

    Utils::fcloseAndNull($this->inHandle);
    Utils::fcloseAndNull($this->outHandle);

    if (!$closed) {
      $this->state = Client::CLOSED;
      $this->fire('disconnected', [$code]);
    }

    return $this;
  }

  /**
   * Frame sending
   */

  protected function sendClose(StatusCode $code = null) {
    if (!$this->writingState->closeFrame) {
      $logCode = $code;

      if ($logCode instanceof StatusCodes\NormalClosure) {
        $logCode = null;
      }

      $this->log("sendClose: sending Close", $logCode, $logCode ? 'warn' : 'info');

      $code or $code = new StatusCodes\NormalClosure;
      $frame = new Frames\Close;

      $this->fire(__FUNCTION__, [&$frame, &$code]);

      $frame->statusCode($code);
      $this->sendRawFrame($frame);

      $this->writingState->closeFrame = $frame;
    } else {
      $this->log("sendClose: Close already sent");
    }
  }

  function sendRawFrame(Frame $frame) {
    return $this->sendRawFrames([$frame]);
  }

  // It doesn't apply any special processing done by active extension(s) and plugins.
  // Bypasses queue, sends immediately.
  function sendRawFrames(array $frames) {
    $first = reset($frames);

    if (!$frames) {
      return;
    } elseif ($this->state !== static::OPEN) {
      EState::fail("sendRawFrames($this->state): state must be OPEN");
    } elseif ($this->writingState->closeFrame) {
      EState::fail("sendRawFrames({$first->describe()}): cannot send anything after the Close frame");
    } elseif ($this->readingState->closeFrame) {
      foreach ($frames as $frame) {
        if (!($frame instanceof Frames\Close)) {
          EState::fail("sendRawFrames({$first->describe()}): cannot send anything but a Close frame after receiving one");
        }
      }
    }

    foreach ($frames as $i => $frame) {
      if ($this->outboundMasked === true) {
        $frame->masker() or $frame->masker(Maskers\Xor32::withNewKey());
      } elseif ($this->outboundMasked === false) {
        $frame->masker(null);
      }

      if ($frame instanceof Frames\Continuation) {
        $this->writingState->lastFragment = $frame;
      } elseif ($frame instanceof DataFrame) {
        $this->writingState->closeMessage($frame);
      }

      $ch = $i ? '+' : ':';
      $this->log("sendRawFrames$ch {$frame->describe()}");
    }

    $this->fire(__FUNCTION__, [&$frames]);
    $this->writingState->bytesOnWire +=
      Frame::multiWriteTo($this->outHandle, $frames);
  }

  function queuePing() {
    $frame = $this->writingState->pingFrame = new Frames\Ping(Utils::randomKey(32));
    $this->queueRawFrame($frame);
  }

  // "A Pong frame MAY be sent unsolicited. This serves as a unidirectional heartbeat. A response to an unsolicited Pong frame is not expected."
  function queueUnsolicitedPong() {
    $frame = new Frames\Pong(Utils::randomKey(32));
    $this->writingState->pongFrame = $frame;
    $this->queueRawFrame($frame);
  }

  // $data - DataSource or string.
  function queueTextData($data, $binary = false) {
    $frame = $binary ? new Frames\BinaryData : new Frames\TextData;

    if (!is_object($data)) {
      $data = new DataSources\StringDS($data);
    }

    $frame->applicationData($data);
    return $this->queueDataFrame($frame);
  }

  function queueBinaryData($data) {
    return $this->queueTextData($data, true);
  }

  function queueJsonData($data) {
    $data = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $this->queueTextData($data);
  }

  function queueDataFrame(DataFrame $frame) {
    return $this->queueDataFrames([$frame]);
  }

  function queueDataFrames(array $frames) {
    $this->fire(__FUNCTION__, [&$frames]);
    $this->extensions->send($frames, [$this, 'queueRawFrames']);
  }

  function queueRawFrame(Frame $frame) {
    $this->queueRawFrames([$frame]);
  }

  function queueRawFrames(array $frames) {
    $this->queue = array_merge($this->queue, $frames);

    if (($length = $this->queueLength()) >= $this->maxQueuePayloads()) {
      $this->log("queue length $length exceeded max size {$this->maxQueuePayloads()} - flushing");
      $this->flushQueue();
    }
  }

  /**
   * Incoming Frame Processing
   */

  function loopTick($maxWait, $iterDuration) {
    parent::loopTick($maxWait, $iterDuration);
    $this->flushQueue();
  }

  function processMessages() {
    if (!$this->isConnected()) {
      EState::fail("processMessages($this->state): state must be OPEN");
    }

    $buffer = null;
    $this->fire('readMessageBuffer', [&$buffer, $this->shortMessageBuffer, $this->inHandle]);

    if (!$this->isConnected()) {
      $this->log('processMessages: disconnected by a handler');
      return;
    }

    // XXX Use non-blocking stream API here and everywhere.
    isset($buffer) or $buffer = Exceptions\EStream::fread($this->inHandle, $this->maxFrame);
    $this->readingState->bytesOnWire += strlen($buffer);

    if (!is_string($buffer)) {
      $this->disconnectAndThrow(Exceptions\EStream::fail('processMessages: reading error'));
    } elseif (!strlen($buffer) and feof($this->inHandle)) {
      // The connection went away. For example, if client was a PHP process that
      // crashed (or terminated on unhandled exception, etc.) - PHP has gracefully
      // closed the stream but the client didn't get a chance to send Close frame.
      //
      // Note that without feof() empty buffer simply indicates there's no data
      // available (e.g. for Client that is reading server's reply).
      $this->log('processMessages: remote closed connection', null, 'warn');
      $this->disconnectAndThrow(new StatusCodes\AbnormalClosure);
    }

    if ($this->logger->logs()) {
      $eof = feof($this->inHandle) ? ' EOF;' : '';
      $dump = Utils::dump(substr($buffer, 0, 16));
      $this->log("processMessages:$eof read ".strlen($buffer)." bytes [$dump] ".substr($buffer, 2, 26));
    }

    $buffer = $this->shortMessageBuffer.$buffer;
    $this->shortMessageBuffer = '';
    $exception = null;

    while (strlen($buffer)) {
      try {
        $func = $this->readingPartial ? 'readPartialMessage' : 'readNewMessage';
        list($frame, $nextFrameOffset) = $this->$func($buffer);
      } catch (Exceptions\ENotEnoughInput $e) {
        // Normally happens when not enough to read frame header or entire ControlFrame.
        // But there can be other cases (e.g. PerMessageDeflate only processes complete
        // frames). In these cases "short" buffer can be arbitrary long.
        $this->log('processMessages: not enough input, accumulating', $e);
        $this->shortMessageBuffer = $buffer;
        break;
      } catch (StatusCode $e) {
        $this->disconnectAndThrow($e);
      } catch (\Throwable $e) {
        goto ex1;
      } catch (\Exception $e) {
        ex1:
        // Problems in parsing socket data must be unrecoverable.
        $this->disconnectAndThrow($e);
      }

      try {
        $this->processRawFrames([$frame]);
      } catch (\Throwable $e) {
        goto ex2;
      } catch (\Exception $e) {
        ex2:
        // Errors at this stage are not supposed to mess up connection state
        // (miss frame boundary, etc.) so it's not necessary to disconnect.
        $this->log("processRawFrames: exception, dropping frame", $e, 'error');
        $exception = $e;
      }

      $buffer = substr($buffer, $nextFrameOffset);
    }

    if ($exception) { throw $exception; }
  }

  function flushQueue() {
    if ($this->isCleanClose()) {
      $this->log('normal mutual closure');
      return $this->disconnect();
    }

    $queue = $this->queue;
    $this->queue = [];
    $this->compactQueue($queue);

    try {
      $this->fire(__FUNCTION__, [&$queue]);
      $this->sendRawFrames($queue);
    } catch (Exceptions\EStream $e) {
      // Remote has probably disconnected. If we don't disconnect, loop() will ignore
      // the exception and continue running.
      $this->disconnectAndThrow($e);
    }
  }

  protected function compactQueue(array &$queue) {
    $seen = [];

    for ($i = count($queue) - 1; $i >= 0; $i--) {
      $frame = $queue[$i];

      if ($frame instanceof Frames\PingOrPong) {
        $type = ($frame instanceof Frames\Ping) ? 'Ping' : 'Pong';
        $ref = &$seen[$type];

        if ($ref) {
          // "If an endpoint receives a Ping frame and has not yet sent Pong frame(s) in response to previous Ping frame(s), the endpoint MAY elect to send a Pong frame for only the most recently processed Ping frame."
          // And there's not much sense in sending multiple pings in one batch, too.
          $this->log("compactQueue($i): discarding ".$frame->describe());
          unset($queue[$i]);
        } else {
          $ref = true;
        }
      }
    }
  }

  protected function readNewMessage($buffer) {
    $header = new FrameHeader;
    $dataOffset = $header->parse(substr($buffer, 0, 14));
    $this->log("readNewMessage: {$header->describe()}");

    if (isset($this->inboundMasked) and $header->mask !== $this->inboundMasked) {
      // "The server MUST close the connection upon receiving a frame that is not masked.  In this case, a server MAY send a Close frame with a status code of 1002 (protocol error)."
      // "An endpoint MUST NOT continue to attempt to process data (including a responding Close frame) from the remote endpoint after being instructed to _Fail the WebSocket Connection_."
      $this->disconnectAndThrow(new StatusCodes\ProtocolError('masked frame expected'));
    }

    $class = Frame::opcodeClass($header->opcode);

    if (!$class) {
      // "If, at any time, an endpoint is faced with data that it does not understand or that violates [...] safety of input [...], the endpoint MAY drop the TCP connection. If the invalid data was received after a successful WebSocket handshake, the endpoint SHOULD send a Close frame with an appropriate status code[...]."
      // "If an unknown opcode is received, the receiving endpoint MUST _Fail the WebSocket Connection_."
      $this->fire('unknownOpcode', [$header]);
      StatusCodes\ProtocolError::fail("unknown opcode $header->opcode");
    }

    $buffer = substr($buffer, $dataOffset);

    list($extData, $appData, $buffer, $partial) =
      $this->extractMessageFrom($buffer, $header);

    if ($partial and is_subclass_of($class, ControlFrame::class)) {
      // Control frames are very short, reading them in full for easier manipulation.
      Exceptions\ENotEnoughInput::fail("partial control frame");
    }

    $frame = $class::from($header, $partial ? $class::FIRST_PART : $class::COMPLETE, $extData, $appData);

    if ($frame instanceof Frames\Continuation) {
      $this->readingState->lastFragment = $frame;
    } elseif ($frame instanceof DataFrame) {
      $this->readingState->closeMessage($frame);
      $this->fire('newMessageStart', [$frame]);
    }

    if ($partial) {
      $this->fire('newPartialFrame', [&$frame]);

      $this->readingPartial = $partial = new PartialRead;
      $partial->header = $header;
      $partial->firstFrame = $frame;
      $partial->nextOffset = strlen($buffer);
    }

    $this->readingState->closePartial($partial ? $frame : null);
    return [$frame, $dataOffset + strlen($buffer)];
  }

  protected function readPartialMessage($buffer) {
    $partial = $this->readingPartial;

    list($extData, $appData, $buffer) =
      $this->extractMessageFrom($buffer, $partial->header, $partial->nextOffset);

    $partial->nextOffset += strlen($buffer);

    $this->log("readPartialMessage: +".strlen($buffer).($partial->isComplete() ? ' (end)' : '').': '.$partial->header->describe());

    $class = Frame::opcodeClass($partial->header->opcode);
    $frame = $class::from($partial->header, $partial->isComplete() ? $class::LAST_PART : $class::MORE_PARTS, $extData, $appData);

    $this->readingPartial->lastPartial = $frame;

    if ($partial->isComplete()) {
      $this->fire('completePartialFrame', [&$frame]);
      $this->readingPartial = null;
    }

    return [$frame, strlen($buffer)];
  }

  protected function extractMessageFrom($buffer, FrameHeader $header, $offset = 0) {
    $chunkLength = min($header->payloadLength - $offset, strlen($buffer));
    $partial = $chunkLength + $offset < $header->payloadLength;
    $partial or $buffer = substr($buffer, 0, $chunkLength);
    list($extData, $appData) = $this->splitPayload($header, $buffer);

    return [$extData, $appData, $buffer, $partial];
  }

  protected function splitPayload(FrameHeader $header, &$buffer) {
    if ($header->mask) {
      list(, $key) = unpack('N', $header->maskingKey);

      $skipped = $this->readingPartial ? $this->readingPartial->nextOffset : 0;

      $msg = sprintf("splitPayload: unmasking %d bytes (%d skipped)", strlen($buffer), $skipped);
      $this->log($msg);

      (new Maskers\Xor32($key))->unmask($buffer, $skipped);
    }

    $appDataStart = 0;
    $this->fire(__FUNCTION__, [$header, &$buffer, &$appDataStart]);

    $extData = $appData = null;

    if ($appDataStart > 0) {
      $this->log("splitPayload: ".($appDataStart < strlen($buffer) ? "appData starts at $appDataStart" : "no appData"));
      $extData = new DataSources\StringDS( substr($buffer, 0, $appDataStart) );
    }

    if ($appDataStart < strlen($buffer)) {
      $appData = new DataSources\StringDS( substr($buffer, $appDataStart) );
    }

    return [$extData, $appData];
  }

  protected function processRawFrames(array $frames) {
    foreach ($frames as $frame) {
      $this->log("processRawFrame: {$frame->describe()}");
    }

    $this->fire(__FUNCTION__, [&$frames]);

    $this->extensions->receive($frames, function (array $frames) {
      foreach ($frames as $frame) {
        $this->processFrame($frame);
      }
    });
  }

  // Even if this throws an exception, it's assumed the connection has recovered.
  // Must disconnect() on unrecoverable errors/exceptions.
  protected function processFrame(Frame $frame) {
    $frame->updateFromData();
    $this->checkFrameHeader($frame->header());
    $this->log("processFrame: {$frame->describe()}");

    $processed = false;
    $this->fire(__FUNCTION__, [&$frame, &$processed]);

    if (!$processed and !$this->doProcessFrame($frame)) {
      $this->disconnectAndThrow(new StatusCodes\ProtocolError("unprocessible frame"));
    }
  }

  protected function checkFrameHeader(FrameHeader $header) {
    $this->fire(__FUNCTION__, [$header]);

    if ($header->rsv1 or $header->rsv2 or $header->rsv3) {
      // "MUST be 0 unless an extension is negotiated that defines meanings for non-zero values.  If a nonzero value is received and none of the negotiated extensions defines the meaning of such a nonzero value, the receiving endpoint MUST _Fail the WebSocket Connection_."
      $this->disconnectAndThrow(new StatusCodes\ProtocolError('one of rsv bits is set'));
    }
  }

  // Returns true if this frame was processed and new frame can be processed.
  protected function doProcessFrame(Frame $frame) {
    if ($frame instanceof DataFrame) {
      // Even if the data wasn't actually processed, it's assumed the frame was
      // processed anyway since data frames can be ignored without impact on the state.
      $this->processDataFrame($frame);
    } elseif ($frame instanceof Frames\Close) {
      // "[...] if the remote endpoint sent a Close frame but the local application has not yet read the data containing the Close frame from its socket's receive buffer, and the local application independently decided to close the connection and send a Close frame, both endpoints will have sent and received a Close frame and will not send further Close frames."
      $this->readingState->closeFrame = $frame;
      $this->gracefulDisconnectInResponseTo($frame);
    } elseif ($frame instanceof Frames\Ping) {
      $this->readingState->pingFrame = $frame;
      $this->log('Ping frame received, will reply');
      $pong = new Frames\Pong($frame->applicationData() ?: '');
      $this->writingState->pongFrame = $pong;
      $this->queueRawFrame($pong);
    } elseif ($frame instanceof Frames\Pong) {
      if ($this->logger->logs()) {
        $pongPayload = $frame->applicationData() ? $frame->applicationData()->readAll() : null;

        if ($this->writingState->pingFrame and $data = $this->writingState->pingFrame->applicationData()) {
          $lastPayload = $data->readAll();
        } else {
          $lastPayload = null;
        }

        if ($pongPayload === $lastPayload) {
          $this->log('Pong frame received');
        } elseif (!$lastPayload) {
          $this->log('unsolicited Pong frame received');
        } else {
          $this->log('Pong frame received but payload differs');
        }
      }

      $this->readingState->pongFrame = $frame;
    } else {
      return false;
    }

    return true;
  }

  // Continuation, BinaryData or TextData.
  protected function processDataFrame(DataFrame $frame) {
    // Partially read frames (non-first) - of their first read frame.
    // Continuations get data processor of the main message.
    // Others (that is, first partial read or complete non-continuation data
    // frames) can get any processor, for which pickProcessorFor is called.
    //
    // This can be overriden if some plugin sets the processor before the frame
    // reaches processDataFrame.
    // Appending is not done on frames for which a processor was created since
    // it's done by its constructor.

    $proc = $frame->dataProcessor();

    if ($proc) {
      // All set.
      return;
    } elseif ($frame instanceof Frames\Continuation) {
      $proc = $this->readingState->messageStart->dataProcessor();
    } elseif (!$frame->isComplete() and !$frame->isFirstPart()) {
      $proc = $this->readingState->partialStart->dataProcessor();
    } else {
      $proc = $this->pickProcessorFor($frame);

      if ($proc) {
        // $frame may well differ from the original frame created in readNew/PartialMessage
        // since extensions and plugins replace it as it passes through the pipeline.
        $frame->dataProcessor($proc);
        $this->readingState->messageStart->dataProcessor($proc);
        $ps = $this->readingState->partialStart;
        $ps and $ps->dataProcessor($proc);
      }

      return;
    }

    if ($proc) {
      $proc->append($frame);
    } else {
      // Might indicate integrity problems (message/partial reading start not tracked).
      $this->log("processDataFrame: no main DataProcessor found", null, 'warn');
    }
  }

  // $frame - never Continuation.
  protected function pickProcessorFor(DataFrame $frame) {
    $res = null;
    $this->fire(__FUNCTION__, [&$res, $frame]);

    if ($res and !($res instanceof DataProcessor)) {
      CodeException::fail('pickProcessorFor: event returned a non-DataProcessor');
    }

    return $res ?: new DataProcessors\Blackhole($frame, $this);
  }

  /**
   * Connection establishment
   */

  protected function prepareStream($handle, $secure) {
    parent::prepareStream($handle, $secure);

    stream_set_chunk_size($handle, $this->maxFrame);
    stream_set_read_buffer($handle, $this->maxFrame);
    stream_set_write_buffer($handle, Frame::$bufferSize);
  }

  protected function checkCommonHeaders(Headers\Bag $headers) {
    if (($ver = $headers->status()->httpVersion()) < 1.1) {
      StatusCodes\UnsupportedHttpVersion::fail("HTTP/$ver: at least HTTP/1.1 is required");
    }

    if (strcasecmp($header = $headers->get('Upgrade'), 'websocket')) {
      StatusCodes\MalformedHttpHeader::fail("Upgrade ($header): must be [websocket]");
    }

    if (!in_array('upgrade', $headers->getTokens('Connection', true))) {
      StatusCodes\MalformedHttpHeader::fail("Connection: must include [upgrade]");
    }
  }

  protected function sendHandshakeError(StatusCode $code = null) {
    $this->log('sendHandshakeError', $code, 'warn');
  }
}

BaseTunnel::globalPlugins(new Plugins\AutoReconnect);
BaseTunnel::globalPlugins(new Plugins\Statistics);
BaseTunnel::globalPlugins(new Plugins\UserAgent);
