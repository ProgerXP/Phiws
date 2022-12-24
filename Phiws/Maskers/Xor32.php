<?php namespace Phiws\Maskers;

use Phiws\CodeException;

class Xor32 extends \Phiws\Masker {
  // 256 KiB.
  const CHUNK_SIZE = 262144;

  // 32-bit integer.
  protected $key;

  static function withNewKey() {
    return new static(static::randomKey());
  }

  static function randomKey() {
    for ($tries = 10; $tries > 0; $tries--) {
      list(, $key) = unpack('V', \Phiws\Utils::randomKey(4));
      if ($key) { return abs($key); }
    }

    CodeException::fail("randomKey: new Xor32 key generation error");
  }

  function __construct($key) {
    // Not accepting $key = 0: x ^ 0 = x.
    if ((!is_int($key) and !is_float($key)) or $key <= 0 or $key > 0xFFFFFFFF) {
      CodeException::fail("Xor32($key): key is out of range");
    }

    $this->key = $key;
  }

  function key() {
    return $this->key;
  }

  function updateHeader(\Phiws\FrameHeader $header) {
    $header->mask = true;
    $header->maskingKey = pack('N', $this->key);
  }

  // Section 5.3.
  // The rationale behind masking - section 10.3.
  function mask(&$payload, $skipBytes = 0) {
    $len = strlen($payload);
    $chunkSize = static::CHUNK_SIZE;
    $key = $this->skipKeyBytes($skipBytes);
    $key = str_repeat(pack('N', $key), min($len, $chunkSize));

    for ($i = 0; $i < $len; $i += $chunkSize) {
      $payload = substr($payload, 0, $i).
                 ($key ^ substr($payload, $i, $chunkSize)).
                 substr($payload, $i + $chunkSize);
    }
  }

  // Symmetric algorithm, mask(mask(X)) = X.
  function unmask(&$payload, $skipBytes = 0) {
    return $this->mask($payload, $skipBytes);
  }

  function skipKeyBytes($count) {
    $keepBytes = 4 - ($count % 4);
    $keepBits = $keepBytes * 8;
    $keepMask = (1 << $keepBits) - 1;
    $key = $this->key;

    return (($key & $keepMask) << (32 - $keepBits))
           | (($key & ~$keepMask) >> $keepBits);
  }
}
