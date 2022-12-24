<?php namespace Phiws\Plugins;

use Phiws\BaseTunnel;
use Phiws\Client;
use Phiws\Exceptions\EStream;
use Phiws\ServerClient;
use Phiws\Utils;

/*
  Note: data in this comment may be heavily outdated.

  php -S doesn't like Client's Upgrade and Server's Connection headers (returns Malformed Request).
  It sends Connection: close and then Connection: upgrade.

  nginx (php-fpm)
  - fastcgi_buffering off - immediate sending of server-generated data
  - fastcgi_request_buffering off - makes client's data immediately available
    to the script although fread() will block and there's no way to see if data is
    available

    fread(fopen('php://input', 'rb'), 10);

    This call will block even after stream_set_blocking(false), and even if input
    has 9 bytes (stream_set_chunk_size() must be even to 10).
    feof() will return false only when client has disconnected, not when there's
    more data to be read.
  - client_max_body_size 16m - Client should send Content-Length: N or request data
    will be empty (and at EOF). When client has written N bytes, PHP will report
    no more input even if the client continues writing it. So reconnect should be made.
    ** should be set in server{}
  - client_body_timeout 600s - If WS connection is idle for too long, nginx will drop it.
  - proxy_set_header content-length 16m - nginx will silently cap client's Content-Length
    to this value if it's larger
  - proxy_set_header Connection/Upgrade - nginx removes these 2 headers from proxied requests
  - proxy_http_version 1.1 - defaults to 1.0 which is not suitable for WS
  - chunked_transfer_encoding off - don't break up server's response; this could be
    also turned off by the client sending HTTP/1.0 (but WS requires 1.1 technically);
    it seems when using proxy_pass chunked doesn't need to be set (despite proxy_http_version 1.1)
    ** server/location (?)
  - timeouts (client_read_timeout, etc.) affect how long the channel can stay connected:
    client request body timeout, server response body timeout, script max run time (this
    applies to normal server as well);
    alternatively to setting nginx timeouts, occasional ping/pong can be sent (nginx
    timouts measure between 2 reads/2 writes, not between first and last read/write)
  - keepalive_timeout 0 - set in location{} to prevent browsers reusing old WS connections
    for normal browser requests (otherwise KA produces very intermettent problems)
    ** server
  - if client doesn't send data to server or if it doesn't send it often and yet the
    server needs to produce some data - server will block while waiting for client
    frames and no data will be produced or sent; ignoreIncoming option can help
    but it has some implications:
    - since no frames will be read, connection will never close cleanly (when both
      endpoints sent and received Close frames); browsers typically close WebSocket
      connection after invoking close() within 30 seconds if they don't receive Close
      frame after sending theirs
    - because of the above, PHP only has one way of knowing when to stop: if the
      client has disconnected; this plugin detects disconnect using connection_aborted();
      for extra measure you can also set ignore_user_abort to on (although they are
      supposed to use the same means of disconnect detection)
    - for bullet-proof termination use request_terminate_timeout of php-fpm (but it
      will terminate scripts even with connected clients)
    - ignore_user_abort/connection_aborted() only work when trying to output something;
      from the PHP Manual:
      "PHP will not detect that the user has aborted the connection until an attempt is made to send information to the client. Simply using an echo statement does not guarantee that information is sent, see flush()."
      therefore occasional data or pings/pongs must be sent; the script will be terminated
      within seconds after this output attempt; Plugins\Ping can be used

  Apache (mod_php):
  - unlike nginx there's no buffering to turn off
  - Content-Length works the same way
  - need to find an alternative to chunked_transfer_encoding and timeout directives
    in Apache
  - seems to be no limit on Content-Length

  stream_set_chunk_size($h, 1) - by default it's 8192; PHP will read this size
  from stream on fread(php://input) regardless of the requested length and will
  block if there are less bytes.

  post_max_size, request method, proxy_request/buffering don't seem to affect anything.

  Summary:
  - client needs to send maximum allowed Content-Length (since browser can't be told
    to do this nginx can add this header); if server responds with 414 then he
    should try smaller size
  - client should track how much data he has already sent and (1) reject all frames
    longer than maximum allowed Content-Length minus required small amount control
    data - such big frames will never fit even into a fresh connection (fragmentation
    plugin can help), (2) when trying to send a frame that's smaller than max
    Content-Length but bigger than currently remaining length - connection should be
    reestablished
  - both parties must be ready that the connection will close (e.g. nginx closing
    it upon timeout) and client should immediately try reconnecting if the connection
    did stay on for a long time (i.e. don't DoS the server when connection was online
    10 seconds - it couldn't have been nginx timeout, too short)
  - server must be aware of blocking read; algorithm for reading new data:
    Read 2 bytes and try parsing them as a complete FrameHeaders;
    If failed, read 1 more byte until success or complete failure (EOF/connection drop);
    once have a complete header - also have payloadLength; read min(PL, MaxChunk)
    normally until this frame was entirely read and processed;
    Start over by reading 2 bytes...

  location = /Phiws/publicentrypoint.php {
    proxy_set_header Content-Length 10485760;
    proxy_set_header Connection Upgrade;
    proxy_set_header Upgrade WebSocket;
    proxy_http_version 1.1;
    proxy_pass http://127.0.0.1:81/ws;
    proxy_read_timeout 600s;
    proxy_send_timeout 600s;
  }

  location = /ws {
    fastcgi_pass   127.0.0.1:9000;
    fastcgi_param  SCRIPT_FILENAME  /.../phiwsjs.php;
    include        fastcgi_params;
  }
*/

class BlockingServer extends \Phiws\StatefulPlugin {
  // Safety buffer. It should be possible to send a frame of complete length (with
  // header) = Content-Length - $freshConnectionLengthMargin.
  protected $freshConnectionLengthMargin = 100;

  // nginx default is 1 MiB. Can be false to not send any (used if nginx is
  // proxying to itself setting Content-Length).
  protected $contentLength = false;
  protected $fallbackMul = 0.75;
  protected $minContentLength = 65536;
  protected $ignoreIncoming = false;

  // Null or callable (BaseTunnel $cx, array &$frames).
  protected $onReconnect;

  // Set for Client and ServerConnection once handshake is complete.
  protected $activeContentLength;

  // Null or int/float (remaining payload length).
  protected $readingPayload;

  /**
   * Accessors
   */

  // For Client only.
  function freshConnectionLengthMargin($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'int');
  }

  // For Client only.
  function initialContentLength($value = null) {
    return Utils::accessor($this, $this->contentLength, func_get_args(), 'int');
  }

  function notSendContentLength() {
    $this->contentLength = false;
    return $this;
  }

  // For Client only.
  // initialContentLength(1M), fallbackMul(0.75) will try 1M, 768K, 512K, 256K
  // unless interrupted by minContentLength.
  function fallbackMul($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), function ($v) {
      if ($v >= 1) {
        \Phiws\CodeException::fail("fallbackMul($v): value must be below 1");
      } else {
        return (float) $v;
      }
    });
  }

  function minContentLength($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'int');
  }

  function activeContentLength() {
    return $this->activeContentLength;
  }

  function ignoreIncoming($value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), 'bool');
  }

  function onReconnect($func = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args());
  }

  /**
   * Plugin
   */

  function events() {
    return array_merge(parent::events(), [
      'clientBuildHeaders', 'clientHandshakeStatus', 'clientCheckHeaders',
      'prepareStream', 'serverCheckHeaders', 'serverBuildHeaders',
      '-serverClientConnected', 'sendRawFrames', 'readMessageBuffer',
    ]);
  }

  protected function reset() {
    $this->activeContentLength = null;
    $this->readingPayload = null;
  }

  function clientBuildHeaders(Client $cx, \Phiws\Headers\Bag $headers) {
    if ($this->contentLength) {
      $this->activeContentLength or $this->activeContentLength = $this->contentLength;
      $headers->set('Content-Length', $this->activeContentLength);
    }
  }

  function clientHandshakeStatus(Client $cx, \Phiws\Headers\Status $status, \Phiws\ServerAddress &$reconnect = null) {
    // Request Entity/URI Too Large.
    if (in_array($status->code(), [413, 414])) {
      $this->activeContentLength *= $this->fallbackMul;

      if ($this->activeContentLength < $this->minContentLength) {
        $cx->log("clientHandshakeStatus: reached minimum possible Content-Length ($this->minContentLength), giving up");
      } else {
        $cx->log("blocking client: trying different Content-Length ($this->activeContentLength)");
        $reconnect = $cx->address();
        return false;
      }
    }
  }

  function clientCheckHeaders(Client $cx, \Phiws\Headers\Bag $headers) {
    $length = $headers->get('X-Phiws-Content-Length');
    $length and $this->activeContentLength = $length;

    if (!$this->activeContentLength) {
      \Phiws\StatusCodes\MalformedHttpHeader::fail("X-Phiws-Content-Length: missing from response but required by a blocking server");
    }

    $kb = round($length / 1024);
    $cx->log("blocking client: connected with Content-Length = {$kb}K");
  }

  function prepareStream(\Phiws\BaseObject $cx, $handle, $secure) {
    // Errors here are critical because PHP will block execution on the first read.
    EStream::stream_set_chunk_size($handle, 1);
    EStream::callValue(0, 'stream_set_read_buffer', $handle, 1);

    stream_set_write_buffer($handle, 1);
  }

  function serverCheckHeaders(ServerClient $cx, \Phiws\Headers\Bag $headers) {
    // Chunk size needs to be set again.
    $this->prepareStream($cx, $cx->inHandle(), false);
    $this->activeContentLength = $headers->get('Content-Length');

    if (!$this->activeContentLength) {
      \Phiws\StatusCodes\MalformedHttpHeader::fail("Content-Length: missing from request but required by the blocking server");
    }
  }

  function serverBuildHeaders(ServerClient $cx, \Phiws\Headers\Bag $headers) {
    $headers->set('X-Phiws-Content-Length', $this->activeContentLength);
  }

  function serverClientConnected(ServerClient $client) {
    while (ob_get_level()) { ob_end_flush(); }
    ob_implicit_flush(true);
    // Force headers to be sent.
    $client->queueUnsolicitedPong();
    $client->flushQueue();

    if ($this->ignoreIncoming and !$client->plugins()->hasClass('Phiws\Plugins\Ping')) {
      $client->plugins()->add((new \Phiws\Plugins\Ping)
        ->interval(5)
        ->justPong(true));
    }
  }

  function sendRawFrames(BaseTunnel $cx, array &$frames) {
    if ($cx instanceof Client) {
      $close = null;

      foreach ($frames as $i => $frame) {
        if ($frame instanceof \Phiws\Frames\Close) {
          $close = $frame;
          array_splice($frames, $i);
          break;
        }
      }

      $remainingBandwidth = $this->activeContentLength - $cx->writingState()->bytesOnWire;
      $totalLength = 0;

      foreach ($frames as $frame) {
        $length = $frame->payloadLength();
        $totalLength += $withOverhead = $length + $this->freshConnectionLengthMargin;

        if ($withOverhead > $this->activeContentLength) {
          \Phiws\StatusCodes\MessageTooBig::fail("frame length ($length) exceeds Content-Length ($this->activeContentLength)");
        }
      }

      if ($totalLength <= $remainingBandwidth) {
        $close and $frames[] = $close;
        // Will fit - send as is.
        return;
      } elseif ($totalLength > $this->activeContentLength) {
        // It's possible to use send pipeline to accurately send frames in different
        // chunks but it's more tricky and not implemented.
        \Phiws\StatusCodes\MessageTooBig::fail("combined frames' lengths ($totalLength) exceeds Content-Length ($this->activeContentLength)");
      }

      if ($frames) {
        $close and $frames[] = $close;

        if ($this->onReconnect) {
          call_user_func_array($this->onReconnect, [$cx, &$frames]);
        }

        $cx->fire('clientRefreshingContentLength', [&$frames]);

        if ($frames) {
          $count = count($frames);
          $cx->log("blocking client: reconnecting to send $count frames starting with {$frames[0]->describe()}");
          $cx->disconnect(new \Phiws\StatusCodes\MessageTooBig("frame length to be sent exceeds remaining Content-Length, reconnecting"));
          $cx->connect($cx->address());
        }

        return false;
      } elseif ($close) {
        // If a Close frame is attempted to be sent and it can't fit, don't re-establish
        // the connection just to send that frame, disconnect.
        $cx->log('final Close frame exceeds remaining Content-Length, disconnecting');
        $cx->disconnect();
        return false;
      }
    }
  }

  function readMessageBuffer(BaseTunnel $cx, &$buffer, $keptBuffer, $handle) {
    if ($this->ignoreIncoming) {
      $buffer = connection_aborted() ? false : '';
      return false;
    }

    if ($cx instanceof ServerClient) {
      if ($this->activeContentLength - $cx->readingState()->bytesOnWire < $cx->maxFrame()
          and !$cx->writingState()->closeFrame) {
        $cx->fire('serverRefreshContentLength');
        $cx->gracefulDisconnect(new \Phiws\StatusCodes\ServiceRestart("approaching Content-Length of $this->activeContentLength"));

        // We will keep reading until Close frame is processed or client drops
        // the connection (which will cause fread() to return immediately).
        // Some data that was already written by the client can be still lost,
        // but it's better than discarding all that remains.
      }

      if ($this->readingPayload <= 0) {
        $cx->log("waiting for new frame");
        $res = $this->readHeader($cx, $keptBuffer, $handle);

        if (!is_array($res)) {
          $dump = \Phiws\Utils::dump($res);
          $cx->log("readMessageBuffer: error reading frame header; new buffer = [$dump]", null, 'warn');

          // This will trigger ENotEnoughInput and store $buffer in $shortMessageBuffer
          // and skip to next tick.
          $buffer = (string) substr($res, strlen($keptBuffer));
          return false;
        }

        list($buffer, $header) = $res;
        $this->readingPayload = $header->payloadLength;
        $cx->log("readHeader({$header->describe()}): read complete header");
      }

      // There are frames without payload.
      if ($this->readingPayload > 0) {
        $size = min($this->readingPayload, $cx->maxFrame());
        $cx->log("readMessageBuffer: reading $size bytes out of $this->readingPayload");
        $buffer .= EStream::fread($handle, $size);

        $this->readingPayload -= $size;
      }

      return false;
    }
  }

  protected function readHeader(BaseTunnel $cx, $buffer, $handle) {
    $len = 2 - strlen($buffer);
    $len > 0 and $buffer .= EStream::fread($handle, $len);

    if (strlen($buffer) >= 2) {
      $str = substr($buffer, 0, 2)."64LENGTHmask";
      $header = new \Phiws\FrameHeader;
      $headerLength = $header->parse($str);

      $remainder = $headerLength - strlen($buffer);
      $cx->log("readHeader({$header->describe()}): header is $headerLength bytes; need to read $remainder more");
      $remainder > 0 and $buffer .= EStream::fread($handle, $remainder);

      if (strlen($buffer) >= $headerLength) {
        $header = new \Phiws\FrameHeader;
        $header->parse($buffer);

        return [$buffer, $header];
      }
    }

    return $buffer;
  }
}
