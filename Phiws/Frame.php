<?php namespace Phiws;

use Phiws\Exceptions\EStream;

abstract class Frame {
  const OPCODE = 0xFF;

  const COMPLETE    = null;
  const FIRST_PART  = '<';
  const MORE_PARTS  = '+';
  const LAST_PART   = '>';

  static $bufferSize = 32768;

  static protected $classes = [
    0x00    => 'Phiws\\Frames\\Continuation',
    0x01    => 'Phiws\\Frames\\TextData',
    0x02    => 'Phiws\\Frames\\BinaryData',
    0x08    => 'Phiws\\Frames\\Close',
    0x09    => 'Phiws\\Frames\\Ping',
    0x0A    => 'Phiws\\Frames\\Pong',
  ];

  protected $timeConstructed;
  protected $timeSent;
  protected $header;
  protected $masker;
  protected $extensionData;
  protected $applicationData;
  // Independent of $fin - indicates that even this frame (which can be
  // further fragmented) was not yet fully read, i.e. $extensionData or
  // $applicationData is incomplete. One of COMPLETE, FIRST_PART, MORE_PARTS, LAST_PART.
  protected $partialOffset = null;
  protected $dataProcessor;
  protected $custom;
  protected $originalFrame;

  static function mapOpcode($opcode, $class) {
    if ($opcode > 0b1111) {
      CodeException::fail("mapOpcode($opcode): opcode is out of range");
    }

    static::$classes[$opcode] = $class;
  }

  static function opcodeClass($opcode) {
    if (isset(static::$classes[$opcode])) {
      return static::$classes[$opcode];
    }
  }

  static function from(FrameHeader $header, $partialOffset = null, DataSource $extData = null, DataSource $appData = null) {
    $frame = new static;
    $frame->header = clone $header;
    $frame->partialOffset = $partialOffset;
    $frame->extensionData = $extData;
    $frame->applicationData = $appData;
    return $frame;
  }

  static function sumPayloadLengths(array $frames) {
    $res = 0;
    foreach ($frames as $frame) { $res += $frame->payloadLength(); }
    return $res;
  }

  function __construct() {
    $this->timeConstructed = microtime(true);
    $this->header = new FrameHeader;
    $this->header->opcode = static::OPCODE;
    // All frames are initially unfragmented.
    $this->header->fin = true;
    $this->custom = new \stdClass;
    $this->init();
  }

  protected function init() { }

  /**
    * Accessors
    */

  // microtime(true).
  function timeConstructed() {
    return $this->timeConstructed;
  }

  function timeSent() {
    return $this->timeSent;
  }

  // FrameHeader (copy) or individual property.
  function header($field = null) {
    $this->updateHeader();
    return $field ? $this->header->$field : clone $this->header;
  }

  // Can return null (no masking).
  function masker(Masker $value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args());
  }

  // Can return null (no data set).
  function extensionData(DataSource $value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args());
  }

  // Can return null (no data set).
  function applicationData() {
    return $this->applicationData;
  }

  function partialOffset() {
    return $this->partialOffset;
  }

  // partialOffset is only used for incoming frames. Outgoing frames will never
  // have it set (always isComplete).
  function isComplete() {
    return $this->partialOffset === static::COMPLETE;
  }

  function isFirstPart() {
    return $this->partialOffset === static::FIRST_PART;
  }

  function hasMoreParts() {
    return $this->partialOffset === static::MORE_PARTS;
  }

  function isLastPart() {
    return $this->partialOffset === static::LAST_PART;
  }

  function dataProcessor(DataProcessor $value = null) {
    return Utils::accessor($this, $this->{__FUNCTION__}, func_get_args());
  }

  function originalFrame() {
    return $this->originalFrame;
  }

  // "The "Payload data" is defined as "Extension data" concatenated with "Application data"."
  function payloadLength() {
    return ( $this->extensionData ? $this->extensionData->size() : 0 )
         + ( $this->applicationData ? $this->applicationData->size() : 0 );
  }

  /**
   * Outputting Header And Payload
   */

  // $moreFrames - array of Frame. If multiple frames are to be written, it's more
  // optimal to do so in one go (small frames will be accumulated into one packet).
  static function multiWriteTo($handle, array $frames) {
    $buffer = '';

    while ($frames) {
      $length = $frames[0]->payloadLength();
      if ($length + strlen($buffer) > static::$bufferSize) {
        break;
      }
      $buffer .= array_shift($frames)->writeToString();
    }

    $size = EStream::fwrite($handle, $buffer);

    foreach ($frames as $frame) {
      $size += $frame->writeTo($handle);
    }

    return $size;
  }

  protected function writeToString() {
    $this->updateHeader();
    $this->timeSent = microtime(true);
    $len = $this->payloadLength();

    $buffer = '';
    $this->extensionData and $buffer .= $this->extensionData->readAll();
    $this->applicationData and $buffer .= $this->applicationData->readAll();
    $this->masker and $this->masker->mask($buffer);
    return $this->header->build().$buffer;
  }

  function writeTo($handle) {
    $this->updateHeader();
    $this->timeSent = microtime(true);

    if ($this->payloadLength() <= static::$bufferSize) {
      // Avoid sending multiple small packets.
      $size = EStream::fwrite($handle, $this->writeToString());
    } elseif ($masker = $this->masker) {
      $size = EStream::fwrite($handle, $this->header->build());

      $sources = [$this->extensionData, $this->applicationData];
      $head = '';

      foreach ($sources as $source) {
        $source and $source->readChunks(static::$bufferSize, function ($buffer)
            use (&$head, $masker, &$size, $handle) {
          $buffer = $head.$buffer;

          $extra = strlen($buffer) - strlen($buffer) % 4;
          $head = substr($buffer, $extra);
          $buffer = substr($buffer, 0, $extra);

          $masker->mask($buffer);
          $size += EStream::fwrite($handle, $buffer);
        });
      }

      if (strlen($head)) {
        $masker->mask($head);
        $size += EStream::fwrite($handle, $head);
      }
    } else {
      $size = EStream::fwrite($handle, $this->header->build());
      $this->extensionData and $size += $this->extensionData->copyTo($handle);
      $this->applicationData and $size += $this->applicationData->copyTo($handle);
    }

    return $size;
  }

  protected function updateHeader() {
    $this->header->payloadLength = $this->payloadLength();

    if ($this->masker) {
      $this->masker->updateHeader($this->header);
    } else {
      $this->header->mask = false;
      $this->header->maskingKey = null;
    }
  }

  function makeFragment($offset, $length) {
    $len = $this->payloadLength();

    if (!$this->applicationData) {
      CodeException::fail("makeFragment: cannot fragment - no \$applicationData");
    } elseif ($offset < 0 or $offset >= $len or $length < 0) {
      CodeException::fail("makeFragment($offset, $length): wrong offset or length");
    } elseif ($this->extensionData) {
      // "A fragmented message is conceptually equivalent to a single larger message [...] the concatenation of the payloads of the fragments in order; however, in the presence of extensions, this may not hold true as the extension defines the interpretation of the "Extension data" present."
      CodeException::fail("makeFragment: cannot fragment with \$extensionData");
    } elseif (!$this->isComplete()) {
      CodeException::fail("makeFragment: cannot fragment with \$partialOffset");
    }

    if ($offset + $length >= $len) {
      $partial = $offset > 0 ? static::LAST_PART : static::COMPLETE;
    } else {
      $partial = $offset > 0 ? static::MORE_PARTS : static::FIRST_PART;
    }

    $handle = Utils::newTempStream();
    try {
      $this->applicationData->copyTo($handle, $offset, $length);
      $newData = new DataSources\Stream($handle, true);
    } catch (\Throwable $e) {
      goto ex;
    } catch (\Exception $e) {
      ex:
      Utils::fcloseAndNull($handle);
      throw $e;
    }

    return $this->makeBareFragment($partial, null, $newData);
  }

  function makeBareFragment($offset, DataSource $extData = null, DataSource $appData = null) {
    switch ($offset) {
    case static::COMPLETE:
    case static::FIRST_PART:
      $frame = clone $this;
      break;

    default:
      $frame = new Frames\Continuation;
      $frame->header = $this->header();
      $frame->header->opcode = $frame::OPCODE;
      $frame->masker = $this->masker;
    }

    // "For a text message sent as three fragments, the first fragment would have an opcode of 0x1 and a FIN bit clear, the second fragment would have an opcode of 0x0 and a FIN bit clear, and the third fragment would have an opcode of 0x0 and a FIN bit that is set."
    $frame->header->fin = in_array($offset, [static::COMPLETE, static::LAST_PART]);
    $frame->originalFrame = $this;
    $frame->extensionData = $extData;
    $frame->applicationData = $appData;

    return $frame;
  }

  function __clone() {
    $this->timeConstructed = time();
    $this->timeSent = null;
  }

  function describe() {
    $this->updateHeader();
    return $this->partialOffset.$this->header->describe();
  }

  function updateFromData() {
    // For overriding.
  }
}
