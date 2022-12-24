<?php namespace Phiws;

use Phiws\StatusCodes\MalformedHttpHeader;
use Phiws\Exceptions\EStream;

// try {
//   $c = new ServerClient($s, $h);
// } catch ... fclose($h);
//
// $c->handshake();
// $c->send...
// $c->processMessages...
class ServerClient extends BaseTunnel {
  const ID_PREFIX = 'SC';

  protected $inboundMasked = true;

  // Null or Server.
  protected $server;
  protected $clientHost;
  protected $clientPort;
  protected $isOutput = false;
  // Array of $_SERVER, only if $isOutput.
  protected $serverInfo = null;

  // The caller must free $handle if this fails.
  static function forStream($handle, $clientHost, $clientPort, Server $server = null) {
    $client = new static($server);
    $client->clientHost = $clientHost;
    $client->clientPort = $clientPort;

    if (!$client->clientHost or !$client->clientPort) {
      CodeException::fail("forStream: invalid client host and/or port");
    }

    $client->inHandle = $client->outHandle = $handle;
    return $client;
  }

  static function forOutput(array $serverInfo, Server $server = null) {
    $client = new static($server);
    $client->isOutput = true;
    $client->serverInfo = $serverInfo;
    $client->clientHost = $client->serverInfo('REMOTE_ADDR');
    $client->clientPort = $client->serverInfo('REMOTE_PORT');

    if (!$client->clientHost or !$client->clientPort) {
      CodeException::fail("forOutput: cannot determine client host and/or port");
    }

    $client->inHandle  = EStream::fopen('php://input',  'rb');
    $client->outHandle = EStream::fopen('php://output', 'wb');

    return $client;
  }

  // Don't call directly, use ::for...() to set up.
  function __construct(Server $server = null) {
    parent::__construct();

    $this->connectedSince = time();
    $this->server = $server;
    $this->state = static::CONNECTING;
  }

  /**
   * Accessors
   */

  function server() {
    return $this->server;
  }

  function clientHost() {
    return $this->clientHost;
  }

  function clientPort() {
    return $this->clientPort;
  }

  function isOutput() {
    return $this->isOutput;
  }

  function serverInfo($key) {
    return isset($this->serverInfo[$key]) ? $this->serverInfo[$key] : null;
  }

  // It seems the only indication to use HTTPS is when target port is 443.
  // "If the connection is happening on an HTTPS (HTTP-over-TLS) port, perform a TLS handshake over the connection."
  function secureClient() {
    return $this->clientPort === 443;
  }

  /**
   * Connection Management
   */

  function disconnect(StatusCode $code = null) {
    $remove = ($this->isConnected() and $this->server);
    parent::disconnect($code);
    $remove and $this->server->clientDisconnected($this);
    return $this;
  }

  // "If the server [...] finds that the client did not send a handshake that matches the description [...] MUST stop [...] and return an HTTP response with an appropriate error code (such as 400 Bad Request)."
  // "If the invalid data is sent during the WebSocket handshake, the server SHOULD return an appropriate HTTP [RFC2616] status code."
  protected function sendHandshakeError(StatusCode $code = null) {
    parent::sendHandshakeError($code);

    $code or $code = new StatusCodes\PreHandshakeCode;
    $headers = $code->httpErrorHeaders();
    $output = $code->httpErrorOutput();

    $this->fire('serverSendHandshakeError', [$code, $headers, &$output]);
    $output = join("\r\n", $output);

    if ($this->isOutput) {
      $headers->output();
      echo $output;
    } else {
      $this->writingState->bytesOnWire +=
        EStream::fwrite($this->outHandle, "$headers\r\n$output");
    }
  }

  // Page 21, section 4.2.1.
  function handshake() {
    if ($this->state !== static::CONNECTING) {
      Exceptions\EState::fail("handshake($this->state): state must be CONNECTING");
    }

    try {
      $enableCrypto = false;

      // Section 4.2.2, point 1.
      if ($this->secureClient()) {
        if (!$this->isOutput) {
          // XXX Looks like $handle context must be changed to supply cert path, etc. in 'ssl' group. See comments on the stream_socket_enable_crypto()'s help page.
          //
          // "If this fails (e.g., the client indicated a host name in the extended client hello "server_name" extension that the server does not host), then close the connection [...]"
          $enableCrypto = true;
        } elseif (strcasecmp($this->serverInfo('HTTPS'), 'ON')) {
          StatusCodes\TlsHandshakeFailed::fail("client requested HTTPS but current connection is over HTTP");
        }
      }

      $this->prepareStream($this->inHandle, $enableCrypto);

      if ($this->isOutput) {
        $this->fillHeadersFromEnv();
        $this->fire('serverReadHeadersFromEnv', [$this->clientHeaders, $this->serverInfo]);
      } else {
        $this->fire('serverReadHeadersFromStream', [$this->clientHeaders]);
        $this->clientHeaders->parseFromStream($this->inHandle, 'Phiws\\Headers\\RequestStatus');
      }

      $this->checkClientHeaders();
      $this->sendHandshake();
      stream_set_timeout($this->inHandle, 0, $this->loopWait * 1000);
      $this->state = Client::OPEN;
    } catch (\Throwable $e) {
      $this->disconnectAndThrow($e);
    } catch (\Exception $e) {
      $this->disconnectAndThrow($e);
    }

    $this->fire('serverClientConnected', []);
  }

  protected function fillHeadersFromEnv() {
    $method = $this->serverInfo('REQUEST_METHOD');
    $uri = $this->serverInfo('REQUEST_URI');

    strtok($this->serverInfo('SERVER_PROTOCOL'), '/');
    $ver = strtok('');

    if (!$method or !$ver) {
      CodeException::fail("fillHeadersFromEnv: missing server info");
    }

    $status = new Headers\RequestStatus($method, $uri, $ver);
    $this->clientHeaders->status($status);

    // XXX PHP ignores multiple headers, only keeping last as 'HTTP_' in
    // $_SERVER. This can create problems because WebSocket spec explicitly allows
    // some headers to duplicate (like Sec-WebSocket-Protocols). HTTP spec specifies
    // that headers that are comma-separated list of values can be merged into one
    // header without side effects, but PHP doesn't do that, dropping all values
    // but last header's.
    // See Section 9.1 (page 49).

    foreach ($this->serverInfo as $key => $value) {
      if (!strncmp($key, 'HTTP_', 5)) {
        $this->clientHeaders->add(substr($key, 5), $value);
      }
    }
  }

  protected function checkClientHeaders() {
    $headers = $this->clientHeaders;
    $this->log("serverCheckHeaders:\n".$headers->join());
    $this->checkCommonHeaders($headers);

    try {
      $this->key = base64_decode($headers->get('Sec-Websocket-Key'));
    } catch (\Throwable $e) {
      $this->key = null;
    } catch (\Exception $e) {
      $this->key = null;
    }

    if (strlen($this->key) !== 16) {
      StatusCodes\MalformedHttpHeader::fail("Sec-WebSocket-Key: bad encoding or key length");
    }

    // Section 4.4.
    $this->version = (int) $headers->get('Sec-Websocket-Version');

    if ($this->version != static::WS_VERSION) {
      StatusCodes\UnsupportedWebSocketVersion::fail("Sec-WebSocket-Version ($this->version): version must be ".static::WS_VERSION);
    }

    $this->fire('serverCheckHeaders', [$headers]);
  }

  protected function sendHandshake() {
    $this->buildStandardHeaders($this->serverHeaders);
    $this->fire('serverBuildHeaders', [$this->serverHeaders]);
    $this->log("sendHandshake:\n".$this->serverHeaders->join());

    if ($this->isOutput) {
      $this->serverHeaders->output();
    } else {
      $this->writingState->bytesOnWire +=
        EStream::fwrite($this->outHandle, $this->serverHeaders->join()."\r\n");

      $this->prepareWrittenStream($this->outHandle);
    }
  }

  protected function buildStandardHeaders(Headers\Bag $headers) {
    $status = new Headers\ResponseStatus(101, Headers\ResponseStatus::SWITCHING);
    $headers->status($status);

    $headers->set("Upgrade", "websocket");
    $headers->set("Connection", "Upgrade");
    $headers->set('Sec-Websocket-Accept', $this->expectedServerKey($this->key()));
  }

  // According to a comment in stream_socket_enable_crypto():
  // "Now, be careful because since PHP 5.6.7, STREAM_CRYPTO_METHOD_TLS_CLIENT (same for _SERVER) no longer means any tls version but tls 1.0 only[...]"
  protected function cryptoMethod() {
    if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_SERVER')) {
      return STREAM_CRYPTO_METHOD_TLSv1_0_SERVER |
             STREAM_CRYPTO_METHOD_TLSv1_1_SERVER |
             STREAM_CRYPTO_METHOD_TLSv1_2_SERVER;
    } else {
      return STREAM_CRYPTO_METHOD_TLS_SERVER;
    }
  }
}
