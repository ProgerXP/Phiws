<?php namespace Phiws;

use Exceptions\ENotEnoughInput;

// Section 5.
class FrameHeader {
  // "An unfragmented message consists of a single frame with the FIN bit set (Section 5.2) and an opcode other than 0."
  // "A fragmented message consists of a single frame with the FIN bit clear and an opcode other than 0, followed by zero or more frames with the FIN bit clear and the opcode set to 0, and terminated by a single frame with the FIN bit set and an opcode of 0."
  public $fin = false;

  // page 28
  // "MUST be 0 unless an extension is negotiated that defines meanings for non-zero values.  If a nonzero value is received and none of the negotiated extensions defines the meaning of such a nonzero value, the receiving endpoint MUST _Fail the WebSocket Connection_."
  //
  // RFC 7692:
  // The "Per-Message Compressed" bit, which indicates whether or not the message is compressed. RSV1 is set for compressed messages and unset for uncompressed messages."
  public $rsv1 = false;
  public $rsv2 = false;
  public $rsv3 = false;

  // XXX If an unknown opcode is received, the receiving endpoint MUST _Fail the WebSocket Connection_.
  public $opcode = 0;

  public $mask = false;

  // page 29
  // "The payload length is the length of the "Extension data" + the length of the "Application data".  The length of the "Extension data" may be zero, in which case the payload length is the length of the "Application data"."
  public $payloadLength = 0;

  // Binary string, such as pack('N', 0x1234FEED) for XOR masking.
  // Page 30.
  // "This field is present if the mask bit is set to 1 and is absent if the mask bit is set to 0."
  public $maskingKey = null;

  // Data is not part of FrameHeader.
  // "Any extension MUST specify the length of the "Extension data", or how that length may be calculated, and how the extension use MUST be negotiated during the opening handshake."
  //public $extensionData;
  //public $applicationData;

  // Page 29.
  static function lengthToBits($len) {
    // [...] in bytes: if 0-125, that is the payload length.  If 126, the following 2 bytes interpreted as a 16-bit unsigned integer are the payload length. If 127, the following 8 bytes interpreted as a 64-bit unsigned integer (the most significant bit MUST be 0) are the payload length. Multibyte length quantities are expressed in network byte order.
    if ($len < 0) {
      CodeException::fail("lengthToBits($len): negative length");
    } elseif ($len < 126) {
      return [chr($len)];
    } elseif ($len < 65536) {
      return [chr(126), chr(($len >> 8) & 0xFF), chr($len & 0xFF)];
    } elseif (PHP_INT_SIZE <= 4) {
      if ($len > PHP_INT_MAX) {
        CodeException::fail("lengthToBits($len): large 64-bit integer cannot be handled by 32-bit PHP");
      } else {
        return str_split( pack('CxxxxN', 127, $len) );
      }
    } elseif ($len & 0x8000000000000000) {
      // ^^ Don't use "$len >= pow(2, 63)" because
      //    0x7FFFFFFFFFFFFFFF >= 0x8000000000000000 is true!
      // "[...] (the most significant bit MUST be 0) [...]".
      // Also see the ABNF for frame-payload-length and RFC Errata 3912.
      StatusCodes\MessageTooBig::fail("tried to encode too big length ($len)");
    } else {
      return str_split( pack('CJ', 127, $len) );
    }
  }

  // Returns [$length, $consumed] where $consumed indicates how many characters
  // were treated as length ($str can include extra characters, but not fewer).
  static function bitsToLength($str) {
    $length = ord($str[0]);

    if ($length < 126) {
      return [$length, 1];
    } elseif ($length === 126) {
      strlen($str) < 3 and ENotEnoughInput::fail('bitsToLength: need 1+2');
      list(, $length) = unpack('C/n', $str);
      return [$length, 3];
    } elseif ($length === 127) {
      strlen($str) < 9 and ENotEnoughInput::fail('bitsToLength: need 1+8');
      if (PHP_INT_SIZE > 4) {
        list(, $length) = unpack('C/J', $str);
      } elseif (substr($str, 1, 4) !== "\0\0\0\0") {
        $length = bin2hex(substr($length, 1));
        CodeException::fail("bitsToLength($length): large 64-bit integer cannot be handled by 32-bit PHP");
      } else {
        list(, $length) = unpack('N', substr($str, 5));
        $length < 0 and $length = (float) sprintf('%u', $length);
      }
      return [$length, 9];
    } else {
      CodeException::fail("bitsToLength($length): invalid first byte");
    }
  }

  // Section 5.2.
  // Returns a binary string to be written onto the wire.
  function build() {
    if ($this->opcode > 0b1111) {
      CodeException::fail("build($this->opcode): opcode is out of range");
    }

    $bits = static::lengthToBits($this->payloadLength);

    array_unshift(
      $bits,

      chr(
          ( !!$this->fin  << 7 )
        + ( !!$this->rsv1 << 6 )
        + ( !!$this->rsv2 << 5 )
        + ( !!$this->rsv3 << 4 )
        + ( $this->opcode & 15 )
      ),

      chr(
          ( !!$this->mask << 7 )
        + (ord(array_shift($bits)) & 127)
      )
    );

    $this->mask and $bits[] = (string) $this->maskingKey;
    return join($bits);
  }

  // $str - binary string, can be longer than necessary (remainder is ignored).
  function parse($str) {
    if (strlen($str) < 2) {
      ENotEnoughInput::fail('parse: partial header');
    }

    list(, $bits1, $bits2) = unpack('C2', $str);

    $this->fin = !!($bits1  & 0x80);
    $this->rsv1 = !!($bits1 & 0x40);
    $this->rsv2 = !!($bits1 & 0x20);
    $this->rsv3 = !!($bits1 & 0x10);
    $this->opcode = $bits1 & 15;

    $this->mask = !!($bits2 & 0x80);

    $lenBits = $bits2 & 0x7F;

    list($this->payloadLength, $lengthByteCount) =
      $this->bitsToLength(chr($lenBits).substr($str, 2, 8));

    // Increase by first byte (fin..opcode).
    $lengthByteCount++;

    if ($this->mask) {
      $keyLen = 4;
      $this->maskingKey = substr($str, $lengthByteCount, $keyLen);
      $lengthByteCount += $keyLen;

      if (strlen($this->maskingKey) !== $keyLen) {
        ENotEnoughInput::fail("parse: missing maskingKey");
      }
    }

    return $lengthByteCount;
  }

  function describe() {
    $flags = [
      $this->fin  ? 'F' : '',
      $this->rsv1 ? '1' : '',
      $this->rsv2 ? '2' : '',
      $this->rsv3 ? '3' : '',
      $this->mask ? 'M' : '',
    ];

    $flags = join($flags) ?: '-';

    $opcodes = [
      0x00  => 'Cont',
      0x01  => 'Text',
      0x02  => 'Data',
      0x08  => 'Clos',
      0x09  => 'Ping',
      0x0A  => 'Pong',
    ];

    $opcodeChar = &$opcodes[$this->opcode];
    $opcodeChar or $opcodeChar = sprintf('0x%02X', $this->opcode);

    $len = $this->payloadLength ? " [$this->payloadLength]" : '';
    return "$opcodeChar ($flags)$len";
  }

  function __toString() {
    return $this->describe();
  }
}
