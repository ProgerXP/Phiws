<?php namespace Phiws\Extensions;

use Phiws\Client;
use Phiws\CodeException;
use Phiws\DataFrame;
use Phiws\DataSources\Stream as DSStream;
use Phiws\Exceptions\EStream;
use Phiws\Frame;
use Phiws\Frames\Continuation;
use Phiws\StatusCodes\NegotiationError;
use Phiws\Utils;

// XXX Consider common ancestor for Plugins/Extensions/Protocols.
class PerMessageDeflate extends \Phiws\Extension {
  const ID = 'permessage-deflate';
  const COMMON_NAME = 'WebSocket Per-Message Deflate';

  const NO_TAKEOVER = 'no_context_takeover';
  const MAX_BITS    = 'max_window_bits';

  // 0 - disable outbound compression, -1 (default level), 1-9 (least to most).
  protected $compressionLevel = -1;
  protected $minSizeToCompress = 300;
  // zlib.deflate parameter (1-9).
  protected $compressionMemory = 5;

  function __destruct() {
    $this->reset();
  }

  /**
   * Accessors
   */

  function disableOutboundCompression() {
    $this->compressionLevel = 0;
    return $this;
  }

  function compressionLevel($level = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), function ($v) {
      if ($v >= -1 and $v <= 9) {
        return (int) $v;
      } else {
        CodeException::fail("compressionLevel($v): level is out of range (-1-9)");
      }
    });
  }

  function minSizeToCompress($bytes = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args());
  }

  function compressionMemory($level = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args(), function ($v) {
      if ($v >= 1 and $v <= 9) {
        return (int) $v;
      } else {
        CodeException::fail("compressionMemory($v): level is out of range (1-9)");
      }
    });
  }

  /**
   * Response (client parsing)
   */

  // Parameters have inverse prefix:
  // - server_* are given by client to tune server's compression;
  //   used by server when sending data and by client when receiving data.
  // - client_* are given by server to tune client's compression;
  //   used by server when reading data and by client when sending data.
  //
  //      C -> S                        C <- S
  //      client_*                      server_*
  //     (client compressing,          (client uncompressing,
  //      server uncompressing)         server compressing)
  //
  // $dir = 's' for sending, 'r' for receving.
  //
  // C/S    $dir    result (assume $param = '...bits')
  // C      s       client...bits       1 1 0
  // C      r       server...bits       1 0 1
  //   S    s       server...bits       0 1 1
  //   S    r       client...bits       0 0 0
  function paramFor($dir, $param) {
    return $this->param($this->paramNameFor($dir, $param));
  }

  function paramNameFor($dir, $param) {
    if (($dir === 's') ^ ($this->tunnel instanceof Client)) {
      $prefix = 'server_';
    } else {
      $prefix = 'client_';
    }

    return $prefix.$param;
  }

  function validateParams() {
    $serverBits = $this->param('server_max_window_bits');
    $clientBits = $this->param('client_max_window_bits');

    if ($serverBits < 8 or $serverBits > 15 or
        $clientBits < 8 or $clientBits > 15) {
      NegotiationError::fail($this->id()."($serverBits, $clientBits): unsupported window size");
    }

    // Ensure remote endpoint won't reuse LZ window.
    //
    // zlib.* filters actually support takeover but it needs extra work. PHP retains
    // the same Zlib context for a given stream (fopen()'ed) so that stream - and
    // its Zlib/LZ context - can be reused by truncating it to 0 when starting
    // sending a new frame.
    if (!$this->paramFor('r', static::NO_TAKEOVER)) {
      $param = $this->paramNameFor('r', static::NO_TAKEOVER);
      NegotiationError::fail($this->id()."($param): must support context takeover");
    }
  }

  protected function parse_server_no_context_takeover($value) {
    if ($value !== true) {
      NegotiationError::fail($this->id()."($value): flag parameter can have no value");
    }

    return $value;
  }

  protected function parse_client_no_context_takeover($value) {
    return $this->parse_server_no_context_takeover($value);
  }

  protected function parse_server_max_window_bits($value) {
    if ($value === true) {
      $value = 15;
    } elseif (!strlen($value) or ltrim($value, '0..9') !== '') {
      NegotiationError::fail($this->id()."($value): window size is not a number");
    }

    return (int) $value;
  }

  protected function parse_client_max_window_bits($value) {
    return $this->parse_server_max_window_bits($value);
  }

/*
  // XXX
  function suggestParams() {
    $this->useFallbackParams();
    $this->params['server_max_window_bits'] = 10;
    return [$this->serializeParams()];
  }
*/

  /**
   * Request (server parsing)
   */

  function useDefaultParams() {
    parent::useDefaultParams();

    $this->params = [
      'server_max_window_bits' => 15,
      'client_max_window_bits' => 15,
    ];
  }

  function useFallbackParams(array $declinedParamSets = []) {
    parent::useFallbackParams($declinedParamSets);

    $this->params += [
      $this->paramNameFor('r', static::NO_TAKEOVER) => true,
    ];
  }

  function retainOfferedParams() {
    // Add back client's settings.
    $res = [];
    $prefix = $this->paramNameFor('s', '');
    $msg = 'retainOfferedParams:';

    foreach ($this->params as $name => $value) {
      if (!strncmp($name, $prefix, strlen($prefix))) {
        $res[$name] = $value;
        $msg .= " $name=$value";
      }
    }

    $this->tunnel->log($msg);
    return $res;
  }

  protected function build_server_no_context_takeover($value) {
    return $value ? true : null;
  }

  protected function build_client_no_context_takeover($value) {
    return $value ? true : null;
  }

  protected function build_server_max_window_bits($value) {
    if (isset($value) and $value < 15) { return (int) $value; }
  }

  protected function build_client_max_window_bits($value) {
    return $this->build_server_max_window_bits($value);
  }

  /**
   * Pipeline
   */

  function sendProcessor(array $frames, \Phiws\Pipeline $pipe) {
    if ($this->compressionLevel !== 0) {
      $proc = $this->makeProcessor(PMDSendProcessor::class, 's', $frames);
      $proc->minSizeToCompress = $this->minSizeToCompress;
      $proc->maxCompressedLength = $this->tunnel->maxFrame();

      return function () use ($proc) {
        // Slightly optimize writing so small compressed frames are sent
        // in one packet.
        return $proc->bulkProcess($this->tunnel->maxFrame());
      };
    }
  }

  function receiveProcessor(array $frames, \Phiws\Pipeline $pipe) {
    $proc = $this->makeProcessor(PMDReceiveProcessor::class, 'r', $frames);
    return [$proc, 'process'];
  }

  protected function makeProcessor($class, $dir, array $frames) {
    $proc = new $class;
    $proc->tunnel = $this->tunnel;
    $proc->moreFrames = $frames;
    $proc->zlibParams = $this->zlibParams($dir);

    $msg = "makeProcessor($dir): Zlib";

    foreach ($proc->zlibParams as $name => $value) {
      $msg .= " $name=$value";
    }

    $this->tunnel->log($msg);

    return $proc;
  }

  function zlibParams($dir) {
    // self  forSend  pf
    // cli    1       cli
    // cli    0       ser
    // ser    1       cli
    // ser    0       ser
    return [
      'window' => -$this->paramFor($dir, static::MAX_BITS),
      'memory' => $this->compressionMemory,
      'level' => $this->compressionLevel,
    ];
  }
}

abstract class PMDProcessor {
  public $tunnel;
  public $moreFrames = [];

  protected $iteration;
  protected $frame;
  protected $applicationData;

  function __destruct() {
    $this->close();
    $this->moreFrames = [];
  }

  function close() {
    $this->iteration = null;
    $this->frame = null;
    $this->applicationData = null;
  }

  // Keeps adding frames to the result until their combined payload length
  // exceeds $size.
  function bulkProcess($size) {
    $res = [];

    do {
      $res = array_merge($res, $this->process());
      $total = 0;

      foreach ($res as $frame) {
        $total += $frame->payloadLength();
      }
    } while ($this->moreFrames and $total < $size);

    return $res;
  }

  function process() {
    if ($this->frame) {
      return $this->processCurrent();
    } else {
      $asIs = [];

      while ($frame = array_shift($this->moreFrames)) {
        if (!$this->isProcessible($frame)) {
          $asIs[] = $frame;
        } else {
          $this->loadNew($frame);
          break;
        }
      }

      return array_merge($asIs, $this->processCurrent());
    }
  }

  function isProcessible(Frame $frame) {
    return ($frame instanceof DataFrame);
  }

  protected function loadNew(DataFrame $frame) {
    $this->close();

    $this->tunnel->log("zlib.loadNew: ".$frame->describe());
    $this->iteration = 0;
    $this->frame = $frame;
    $this->applicationData = $frame->applicationData();
  }

  protected function processCurrent() {
    if ($this->frame) {
      $this->tunnel->log("zlib.processCurrent(#$this->iteration): ".$this->frame->describe());
      $res = $this->doProcessCurrent($finished);
      $this->iteration++;
      $finished and $this->frame = null;
      return $res;
    } else {
      return [];
    }
  }

  abstract protected function doProcessCurrent(&$finished);
}

class PMDSendProcessor extends PMDProcessor {
  public $minSizeToCompress;
  public $maxCompressedLength = 262144;
  public $zlibParams = [];

  protected $appDataOffset;
  protected $lastChunks;

  function isProcessible(Frame $frame) {
    if (parent::isProcessible($frame) and !$frame->extensionData()) {
      $appData = $frame->applicationData();
      $length = $appData->size();

      if ($length > 0) {
        if ($frame instanceof Continuation) {
          return !empty( $this->tunnel->writingState()->messageCustom->pmdCompressing );
        } else {
          return $length >= $this->minSizeToCompress;
        }
      }
    }
  }

  protected function loadNew(DataFrame $frame) {
    parent::loadNew($frame);
    $this->appDataOffset = 0;
    $this->lastChunks = [];

    if (!($frame instanceof Continuation)) {
      $header = $frame->header();
      // RFC 7692, section 6:
      // "[...] allocates the RSV1 bit of the WebSocket header for PMCEs and calls the bit the "Per-Message Compressed" bit.  On a WebSocket connection where a PMCE is in use, this bit indicates whether a message is compressed or not."
      $header->rsv1 = true;
      // RFC 7692:
      // "PMCEs do not change the opcode field. The opcode of the first frame of a compressed message indicates the opcode of the original message."
      $this->frame = $frame->from($header, null, null, $this->applicationData);
    }
  }

  protected function readAndCompressChunk(&$finished) {
    // Not sure if php-zlib does this padding (also see RFC 1979) or if it uses BFINAL
    // blocks except when in the end; RFC 7692, page 19 (section 7.2.1 Compression)
    // "When any DEFLATE block with the "BFINAL" bit set to 1 doesn't end at a byte boundary, an endpoint MUST add minimal padding bits of 0 to make it end at a byte boundary.  The next DEFLATE block follows the padded data if any."

    // RFC 7692: 7.2.3.4.  Using a DEFLATE Block with "BFINAL" Set to 1.
    // Unsure if PHP does it.

    // When compressing empty string, php-zlib (functions and filter) returns 0300
    // (BFINAL + BTYPE = fixed). RFC 7692 requires/suggests returning 00 instead but
    // this should still work (7.2.3.6.  Generating an Empty Fragment).
    //
    // When uncompressing string = 0x00, gzinflate/zlib_encode error but zlib.inflate
    // filter doesn't (returns empty string as expected).

    // zlib.inflate reads data as available and because of this it can work before
    // a BFINAL block is encountered (if ever). gzinflate/zlib_encode differ:
    // they expect complete stream; because of RFC 7692, 7.2.1.  Compression:
    //
    // "If the resulting data does not end with an empty DEFLATE block with no compression (the "BTYPE" bits are set to 00), append an empty DEFLATE block with no compression to the tail end."
    // "Remove 4 octets (that are 0x00 0x00 0xff 0xff) from the tail end. After this step, the last octet of the compressed data contains (possibly part of) the DEFLATE header bits with the "BTYPE" bits set to 00."
    //
    // ...such a stream will fail gzinflate with "data error" unless these bytes were
    // appended: 0000FFFF03 (first 4 finish the empty no-compression block, last
    // byte adds a BFINAL block to mark end of data, otherwise zlib also errors).

    // RFC 7692 allows multiple BFINAL block in one compressed stream:
    // "An endpoint MAY use both DEFLATE blocks with the "BFINAL" bit set to 0 and DEFLATE blocks with the "BFINAL" bit set to 1."
    //
    // Apparently this is to allow simple concatenation of multiple compressed streams:
    // "When any DEFLATE block with the "BFINAL" bit set to 1 doesn't end at a byte boundary, an endpoint MUST add minimal padding bits of 0 to make it end at a byte boundary.  The next DEFLATE block follows the padded data if any."
    //
    // php-zlib functions and filter stop after processing this block even if there is
    // still data in the stream. So returned data is truncated if the client
    // has used BFINAL blocks in any but last position. There's no way to detect
    // this case in PHP without manually parsing the stream into bits. Not sure if
    // BFINAL blocks are used in the wild and how much of a problem is that.
    //
    // zlib.inflate filter keeps state and produces data when input length is sufficient.
    // It's possible to maintain arbitrary long context history like this:
    //
    //   $h = fopen('php://temp', 'w+b');
    //   stream_filter_append($h, ...);
    //
    //   while (...) {
    //     fwrite($h, 'chunk');
    //     rewind($h);
    //     $decompressed = fread($h, ...);
    //     ftruncate($h, 0);
    //   }

    // Recreating a stream from scratch for each chunk means we are losing some LZ
    // context info and compression ration might be lower (because Zlib doesn't know
    // about the data preceding this chunk). With large enough $maxCompressedLength,
    // this should be no problem.
    $chunk = DSStream::newTemporary();
    $handle = $chunk->handle();
    $filter = EStream::stream_filter_append($handle, 'zlib.deflate', STREAM_FILTER_WRITE, $this->zlibParams);

    $rem = $this->maxCompressedLength;
    do {
      $res = $this->applicationData->copyEx($handle, $this->offset, $rem);
      $rem = $this->maxCompressedLength - EStream::ftell($handle);
    } while ($res > 0 and $rem > 0);

    EStream::callValue(true, 'stream_filter_remove', $filter);

    $finished = $rem > 0;

    if ($finished) {
      // Sometimes php-zlib uses BFINAL blocks. RFC 7692:
      // "On platforms on which the flush method using an empty DEFLATE block with no compression is not available, implementors can choose to flush data using DEFLATE blocks with "BFINAL" set to 1."
      EStream::fwrite($handle, "\0");
    }

    $chunk->updateSize();
    return $chunk;
  }

  protected function doProcessCurrent(&$finished) {
    // Special cases:
    // - frame has no appData (rejected by isProcessible)
    // - frame has zero-length appData (rejected by isProcessible)
    // - length of compressed appData is even to maxCompressedLength
    // - compressed appData is under maxCompressedLength (fits into 1 frame)
    //
    // To detect last 2 cases and avoid sending frame with empty payload (below) when
    // they happen, one extra compressed chunk is kept in advance.
    //
    // RFC 7692, 7.2.3.6.  Generating an Empty Fragment:
    // "The single octet 0x00 contains the header bits with "BFINAL" set to 0 and "BTYPE" set to 00, and 5 padding bits of 0."

    $this->tunnel->writingState()->messageCustom->pmdCompressing = true;

    $chunks = $this->lastChunks;
    $chunks and $this->log("picked up ".count($chunks)." last chunks");

    while (count($chunks) < 2 and !$finished) {
      $chunk = $this->readAndCompressChunk($finished);
      $size = $chunk->size();

      if ($size) {
        $chunks[] = $chunk;

        $byte = ord($chunk->readHead(1));
        $info = "compressed new chunk, size $size";
        ($byte & 1) and $info .= ", BFINAL" ;

        switch ($byte & 6) {
        case 0:  $info .= ', no comp'; break;
        case 2:  $info .= ', fixed codes'; break;
        case 4:  $info .= ', dynamic codes'; break;
        case 6:  $info .= ', reserved BTYPE'; break;
        }

        $this->log($info);
      }
    }

    if ($finished and !$chunks) {
      $chunks[] = $chunk = new \Phiws\DataSources\StringDS("\0");
    }

    $return = $finished ? array_splice($chunks, 0) : [array_shift($chunks)];
    $this->lastChunks = $chunks;

    foreach ($return as $i => &$ref) {
      if ($this->iteration + $i === 0) {
        $offset = (count($return) === 1 and $finished) ? Frame::COMPLETE : Frame::FIRST_PART;
      } elseif (!$finished or count($return) > $i + 1) {
        $offset = Frame::MORE_PARTS;
      } else {
        $offset = Frame::LAST_PART;
      }

      $ref = $this->frame->makeBareFragment($offset, null, $ref);
      $this->log("made fragment: ".$ref->describe());
    }

    $this->log("returning ".count($return)." fragment(s), ".count($chunks)." in backlog");
    return $return;
  }

  function log($msg) {
    $this->tunnel->log("zlib.deflate: $msg");
  }
}

class PMDReceiveProcessor extends PMDProcessor {
  public $zlibParams = [];

  function isProcessible(Frame $frame) {
    if (parent::isProcessible($frame)) {
      if ($frame instanceof Continuation) {
        // RFC 7692, page 11:
        // "An endpoint MUST NOT set the "Per-Message Compressed" bit of control frames and non-first fragments of a data message. An endpoint receiving such a frame MUST _Fail the WebSocket Connection_."
        if ($frame->header()->rsv1) {
          $this->tunnel->disconnectAndThrow(new \Phiws\StatusCodes\ProtocolError("continuation frame must have RSV1 unset: {$frame->describe()}"));
        }
      }

      return $this->tunnel->readingState()->messageStart->header()->rsv1;
    }
  }

  protected function doProcessCurrent(&$finished) {
    $finished = true;

    // Incoming frames can be mangled in two ways:
    // - Continuations - separate frames as far as compression is concerned (as
    //   long as *_no_context_takeover is set).
    // - partial readings - since it's essentially the same compressed stream
    //   its state has to be maintained; there's just one way to do it without
    //   buffering entire previous stream is by reusing the same intermediate
    //   stream, truncating it on new portion of input.

    $appData = $this->applicationData;

    $makeResult = function ($appData) {
      $header = $this->frame->header();
      $header->rsv1 = false;

      $offset = $this->frame->partialOffset();
      $extData = $this->frame->extensionData();
      return [$this->frame->from($header, $offset, $extData, $appData)];
    };

    if (!$appData) {
      // Normal frame without appData or a partial frame with only header read.
      $this->log('frame without appData');
      return $makeResult(null);
    } elseif ($this->frame->isComplete() and ($appData instanceof DSStream)) {
      // Completely read frame with a Stream source. Simply attach zlib.inflate.
      $this->log('completely read Stream frame');
      $resData = new \Phiws\DataSources\NonSeekableStream($appData->handle(), false);

      EStream::stream_filter_append($resData->handle(),
        'zlib.inflate', STREAM_FILTER_READ, $this->zlibParams);

      return $makeResult($resData);
    } else {
      // A partially read frame with at least some data or a complete frame with
      // non-Stream source. For partially read frames, have to maintain zlib
      // context because inflate will need data from last partially read frame
      // until frame has been read to the end (LAST_PART).
      //
      // Problem is, we don't know only uncompressed length, which is not critical,
      // but we don't know if we have enough data to produce at least 1 uncompressed
      // byte. If the client has sent empty frame with 0x00 filler as per RFC -
      // it will never produce any output.
      //
      // We reuse the same DataSource for each partial sub-read without copying
      // its parts to new sources (as done in PMDSendProcessor) because it's
      // unlikely that old source will be retained; typically its data is read and
      // the source discarded (see BufferAndTrigger for example).

      if ($custom = $this->tunnel->readingState()->partialCustom) {
        $cxData = &$custom->pmdZlibContext;
      }

      if (!isset($cxData)) {
        $this->log('new Zlib context');
        $cxData = \Phiws\DataSources\NonSeekableStream::newTemporary();

        EStream::stream_filter_append($cxData->handle(),
          'zlib.inflate', STREAM_FILTER_READ, $this->zlibParams);
      } else {
        $this->log('reusing Zlib context');
      }

      EStream::ftruncate($cxData->handle());
      // Copy raw compressed data to be expanded on read (this must be more efficient
      // than storing uncompressed - it'll take more cycles to exhaust because it's
      // typically larger than compressed stream).
      $appData->copyTo($cxData->handle());
      $cxData->updateSize();
      // Rewinding with a zlib filter is unpredictable (returns error on read) hence
      // using NonSeekableStream, but after truncating and prior to reading it's okay
      // so directly rewinding it here.
      EStream::rewind($cxData->handle());

      $this->logCompressedSource($appData);

      return $makeResult($cxData);
    }
  }

  protected function logCompressedSource(\Phiws\DataSource $appData) {
    if ($appData instanceof DSStream) {
      $handle = $appData->handle();
      $appData->seek();
      $head = EStream::fread($handle, 6);
      EStream::fseek($handle, -6, SEEK_END);
      $tail = EStream::fread($handle, 6);
    } else {
      $data = (string) $appData;
      $head = substr($data, 0, 6);
      $tail = substr($data, -6);
    }

    $length = $appData->size();
    $head = \Phiws\Utils::dump($head);
    $tail = \Phiws\Utils::dump($tail);
    $this->log("compressed source ($length bytes): head [$head], tail [$tail]");
  }

  function log($msg) {
    $this->tunnel->log("zlib.inflate: $msg");
  }
}
