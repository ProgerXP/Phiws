<?php namespace Phiws;

use Phiws\BaseTunnel as BT;
use Phiws\Client as C;
use Phiws\Server as S;
use Phiws\ServerClient as SC;

abstract class Plugin implements PluginInterface {
  function cloneFor($context) {
    return clone $this;
  }

  function isGlobalHook() { }
  function firing($event, BaseObject $cx, array $args) { }

  /* Universal events */
  function resetContext(BaseObject $cx) { }
  // $options as given to stream_context_create().
  function makeStreamContext(BaseObject $cx, array &$options) { }
  function prepareStream(BaseObject $cx, $handle, $secure) { }
  function loopTick(BaseObject $cx, $maxWait, $iterDuration) { }

  function gracefulDisconnect(BT $cx, StatusCode $code = null) { }
  function disconnect(BT $cx, StatusCode $code = null) { }
  function disconnected(BT $cx, StatusCode $code = null) { }
  function sendClose(BT $cx, Frame &$frame, StatusCode &$code) { }
  function sendDataFrames(BT $cx, array &$frames) { }
  // $frame can be set to null to not send it.
  function sendRawFrames(BT $cx, array &$frames) { }
  function unknownOpcode(BT $cx, FrameHeader $header) { }
  // Determines where extensionData ends and applicationData beings.
  // Defaults to $appDataStart = 0, i.e. no extensionData.
  // Warning: do not change $buffer's length! (Changing contents is okay.)
  function splitPayload(BT $cx, FrameHeader $header, &$buffer, &$appDataStart) { }
  // $frame->partialOffset() === '+' or ']'.
  function newPartialFrame(BT $cx, Frame &$frame) { }
  // partialOffset() === ']'.
  function completePartialFrame(BT $cx, Frame &$frame) { }
  // disconnectAndThrow() on error.
  function checkFrameHeader(BT $cx, FrameHeader $header) { }
  function readMessageBuffer(BT $cx, &$buffer, $keptBuffer, $handle) { }
  function processRawFrames(BT $cx, array &$frames) { }
  // If $processed is set to true, frame is discarded and standard handler not ran.
  function processFrame(BT $cx, Frame &$frame, &$processed) { }
  // A "message" is a data frame with all of its continuations, as defined by the RFCs.
  function newMessageStart(BT $cx, Frame $frame) { }
  // $res should be set to a DataProcessor.
  function pickProcessorFor(BT $cx, &$res, DataFrame $frame) { }
  function flushQueue(BT $cx, array &$frames) { }

  /* BufferAndTrigger */
  // Null means that data was empty in the frame (zero length).
  function bufferedFrameComplete(BT $cx, DataSource $applicationData = null, DataSource $extensionData = null) { }

  /* Client events */
  function clientConnect(C $cx, ServerAddress $addr, $isReconnect) { }
  function clientHandshakeStatus(C $cx, Headers\Status $status, ServerAddress &$reconnect = null) { }
  // At this point final verification can be made, for example, negotiated extensions 
  // can be reviewed and a ClientExtensionsNotNegotiated can be thrown on error.
  function clientConnected(C $cx) { }
  // Handles are initially null. Can be set to bypass standard socket creation.
  // $in/outHandle's can be the same when it returns; both must be set.
  function clientOpenSocket(C $cx, &$inHandle, &$outHandle) { }
  function clientOpenedSocket(C $cx, $handle) { }
  function clientBuildHeaders(C $cx, Headers\Bag $headers) { }
  function clientReadHeaders(C $cx, Headers\Bag $headers) { }
  function clientCheckHeaders(C $cx, Headers\Bag $headers) { }

  /* Server events */
  // Called before disconnecting connected clients.
  function serverDisconnectAll(S $cx, $graceful) { }
  function serverStopped(S $cx) { }
  function serverStart(S $cx) { }
  function serverStarted(S $cx, $handle) { }
  // Throw an exception to drop this client.
  function serverClientAccepting(S $cx, $host, $port) { }
  function serverClientAccepted(S $cx, ServerClient $client) { }
  function serverClientDisconnected(S $cx, ServerClient $client) { }

  /* ServerClient events */
  function serverSendHandshakeError(SC $cx, StatusCode $code, Headers\Bag $headers, array &$output) { }
  function serverReadHeadersFromStream(SC $cx, Headers\Bag $headers) { }
  function serverReadHeadersFromEnv(SC $cx, Headers\Bag $headers, array $serverInfo) { }
  function serverCheckHeaders(SC $cx, Headers\Bag $headers) { }
  function serverBuildHeaders(SC $cx, Headers\Bag $headers) { }
  // After successful handshake.
  function serverClientConnected(SC $client) { }

  /* Other sources */
  // $frame can be sent to null, connection won't be reconnected.
  function clientRefreshingContentLength(Plugins\BlockingServer $cx, array &$frames) { }
  function serverRefreshingContentLength(Plugins\BlockingServer $cx) { }
}
