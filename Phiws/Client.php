<?php namespace Phiws;

use Phiws\Exceptions\EStream;

/*
  $client = new Client;
  $client->connect(new ServerAddress('127.0.0.1', 8080));
  $client->send...
*/
class Client extends BaseTunnel {
  const ID_PREFIX = 'C';

  protected $outboundMasked = true;

  // ServerAddress.
  protected $address;

  // Null or Exception.
  protected function init() {
    parent::init();

    $this->timeout = +ini_get('default_socket_timeout') ?: $this->timeout;
  }

  function address() {
    return clone $this->address;
  }

  function connect(ServerAddress $addr, $isReconnect = false) {
    if ($this->state !== static::CLOSED) {
      Exceptions\EState::fail("connect($this->state): state must be CLOSED");
    }

    $this->reset();

    $this->key = Utils::randomKey(static::KEY_LENGTH);
    $this->version = static::WS_VERSION;
    $this->address = $addr;

    $this->log("clientConnect: {$addr->uri()}");
    $this->fire('clientConnect', [$addr, $isReconnect]);

    $this->state = static::CONNECTING;
    try {
      $this->connectedSince = time();
      $this->openClientSocket();
      $this->fire('clientOpenedSocket', [$this->inHandle]);
      $addr = $this->handshake();

      if ($addr) {
        $this->disconnect();
        $this->log("reconnecting to {$addr->uri()}");
        return $this->connect($addr, true);
      }

      // Timeout affects how long we are going to wait for new data. fread()
      // will block for at most this value. If it's too high - loop will stuck on
      // processMessages and loopTick won't be reached.
      stream_set_timeout($this->inHandle, 0, $this->loopWait * 1000);

      $this->state = static::OPEN;
    } catch (\Throwable $e) {
      goto ex;
    } catch (\Exception $e) {
      ex:
      $this->log('clientConnect: exception', $e, 'error');
      $this->disconnectAndThrow($e);
    }

    $this->fire('clientConnected');
  }

  // Section 7.2.3.
  // "[...] Clients SHOULD use some form of backoff when trying to reconnect after abnormal closures as described in this section."
  function reconnectOnError() {
    // XXX
    usleep(1000 * mt_rand(100000, 100000));
    // "Should the first reconnect attempt fail, subsequent reconnect attempts SHOULD be delayed by increasingly longer amounts of time, using a method such as truncated binary exponential backoff."
  }

  protected function openClientSocket() {
    $this->fire('clientOpenSocket', [&$this->inHandle, &$this->outHandle]);

    if (!$this->inHandle or !$this->outHandle) {
      $addr = 'tcp://'.$this->address->host().':'.$this->address->port();
      $code = $error = '';

      try {
        $handle = $this->inHandle = $this->outHandle =
          stream_socket_client($addr, $code, $error,
            $this->timeout, STREAM_CLIENT_CONNECT, $this->makeStreamContext());
      } catch (\Throwable $e) {
        goto ex;
      } catch (\Exception $e) {
        ex:
        Exceptions\EConnect::fail("openClientSocket($addr): stream_socket_client() error: ".$e->getMessage());
      }

      if (!$handle) {
        Exceptions\EConnect::fail("openClientSocket($addr): stream_socket_client() error $code: $error");
      }

      $this->prepareStream($handle, $this->address->secure());
    }
  }

  protected function cryptoMethod() {
    if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
      return STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT |
             STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT |
             STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
    } else {
      return STREAM_CRYPTO_METHOD_TLS_CLIENT;
    }
  }

  // Page 16.
  protected function handshake() {
    $headers = $this->clientHeaders;
    $this->buildStandardHeaders($headers);
    $this->fire('clientBuildHeaders', [$headers]);

    $this->log("clientBuildHeaders:\n".$headers->join());
    EStream::fwrite($this->outHandle, $headers->join()."\r\n");

    $this->prepareWrittenStream($this->outHandle);

    $headers = $this->serverHeaders->parseFromStream($this->inHandle, 'Phiws\\Headers\\ResponseStatus');
    $this->log("clientReadHeaders:\n".$headers->join());
    $this->fire('clientReadHeaders', [$headers]);

    $status = $this->serverHeaders->status();

    if ($status->code() !== 101) {
      return $this->handleHandshakeStatus($status);
    }

    $this->checkCommonHeaders($headers);
    $this->fire('clientCheckHeaders', [$headers]);
  }

  protected function buildStandardHeaders(Headers\Bag $headers) {
    $addr = $this->address();

    // "The method of the request MUST be GET, and the HTTP version MUST be at least 1.1."
    $status = new Headers\RequestStatus("GET", $addr->resourceName());
    $headers->status($status);

    $headers->set("Host", $addr->host().$addr->portWithColon());
    $headers->set("Upgrade", "websocket");
    $headers->set("Connection", "Upgrade");
    $headers->set("Sec-Websocket-Key", base64_encode($this->key()));
    $headers->set("Sec-Websocket-Version", $this->version());
  }

  protected function checkCommonHeaders(Headers\Bag $headers) {
    parent::checkCommonHeaders($headers);

    $status = $headers->status();

    if ($status->code() !== 101 or $status->text() !== $status::SWITCHING) {
      StatusCodes\MalformedHttpHeader::fail("HTTP status ($status): must be [101 ".$status::SWITCHING."]");
    }

    $accept = $headers->get('Sec-Websocket-Accept');
    $expected = $this->expectedServerKey($this->key());

    if (trim($accept) !== $expected) {
      StatusCodes\MalformedHttpHeader::fail("Sec-WebSocket-Accept ($accept): must be [$expected]");
    }
  }

  protected function handleHandshakeStatus(Headers\Status $status) {
    $this->log("handleHandshakeStatus: {$status->code()} {$status->text()}");
    $reconnectAddr = null;
    $this->fire('clientHandshakeStatus', [$status, &$reconnectAddr]);

    if ($reconnectAddr) {
      return $reconnectAddr;
    } else {
      StatusCodes\InvalidHttpStatus::fail($status->text(), $status->code());
    }
  }
}
